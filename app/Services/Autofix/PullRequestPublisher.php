<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Models\FixJob;
use App\Models\ProjectRepository;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The only holder of a write-scoped GitHub token in the whole application.
 *
 * It takes the change set the validator already applied in memory, pushes it
 * through the Git Data API — blobs, tree, commit, ref — and opens the pull
 * request. There is no clone and no working tree: the parent commit is the one
 * the diff was validated against, so what lands on the branch is exactly what
 * was checked.
 *
 * The token is minted here, used, and dropped. It is never cached, never
 * logged, and never travels to Ayos.
 */
class PullRequestPublisher
{
    /**
     * The permissions the write token is scoped to.
     *
     * A branch and a pull request need no more than this, and `workflows` is
     * never among them — an agent that cannot write a workflow file cannot
     * hand itself CI credentials.
     *
     * @var array<string, string>
     */
    public const WRITE_PERMISSIONS = ['contents' => 'write', 'pull_requests' => 'write'];

    /**
     * The mode a file is written with when nothing knows better.
     */
    public const DEFAULT_FILE_MODE = '100644';

    /**
     * The branch prefix every autofix branch lives under.
     */
    public const BRANCH_PREFIX = 'autofix/';

    /**
     * How much of the fingerprint names the branch.
     */
    public const BRANCH_FINGERPRINT_LENGTH = 12;

    /**
     * The segment a custom job's branch is named under.
     *
     * Error branches are named by fingerprint, which a custom job has none of.
     * Its uuid is the only stable handle it has, and the `custom-` segment
     * keeps the two kinds of branch legible side by side in a branch list.
     */
    public const BRANCH_CUSTOM_PREFIX = 'custom-';

    /**
     * How much of the uuid names a custom job's branch.
     */
    public const BRANCH_UUID_LENGTH = 12;

    /**
     * How much of the request is quoted in a custom job's title.
     */
    public const REQUEST_EXCERPT_LIMIT = 600;

    /**
     * How long a pull request title may be before it is cut.
     */
    public const TITLE_LIMIT = 72;

    public function __construct(
        private readonly GitHubAppTokenService $tokens,
        private readonly GitHubRepositoryClient $github,
        private readonly TaskRenderer $taskRenderer,
    ) {}

    /**
     * Push the validated change set and open the pull request.
     *
     * @throws GitHubAppException
     */
    public function publish(FixJob $job, AppliedDiff $applied): void
    {
        $repository = $job->repository;
        $repo = trim($repository->repo_full_name, '/');

        $token = $this->tokens->installationToken($repository->installation, $repo, self::WRITE_PERMISSIONS);

        $entries = [];

        foreach ($applied->changes as $change) {
            $entries[] = $change->isDeletion()
                ? ['path' => $change->path, 'mode' => $change->mode, 'type' => 'blob', 'sha' => null]
                : [
                    'path' => $change->path,
                    'mode' => $change->mode,
                    'type' => 'blob',
                    'sha' => $this->github->createBlob($token, $repo, (string) $change->content),
                ];
        }

        $tree = $this->github->createTree($token, $repo, $applied->treeSha, $entries);
        $title = $this->title($job);
        $commit = $this->github->createCommit($token, $repo, $this->commitMessage($job, $title), $tree, $applied->headSha);
        $branch = $this->branch($job);

        $this->github->createOrUpdateBranch($token, $repo, $branch, $commit);

        $pull = $this->openPullRequest($token, $repo, $branch, $repository, $title, $this->body($job, $applied));

        $job->forceFill([
            'status' => FixJobStatus::PrOpened,
            'pr_number' => $pull['number'],
            'pr_url' => $pull['url'],
            'failure_reason' => null,
        ])->save();
    }

    /**
     * The branch this job's work lives on.
     */
    public function branch(FixJob $job): string
    {
        if ($job->type === FixJobType::Custom) {
            return self::BRANCH_PREFIX.self::BRANCH_CUSTOM_PREFIX.mb_substr($job->uuid, 0, self::BRANCH_UUID_LENGTH);
        }

        return self::BRANCH_PREFIX.mb_substr((string) $job->fingerprint, 0, self::BRANCH_FINGERPRINT_LENGTH);
    }

    /**
     * Open the pull request, or adopt the one an earlier attempt left open.
     *
     * @return array{number: int, url: string}
     *
     * @throws GitHubAppException
     */
    protected function openPullRequest(string $token, string $repo, string $branch, ProjectRepository $repository, string $title, string $body): array
    {
        try {
            return $this->github->createPullRequest($token, $repo, $branch, $repository->default_branch, $title, $body);
        } catch (GitHubAppException $exception) {
            if ($exception->statusCode() !== 422) {
                throw $exception;
            }

            /*
             * 422 on this call is nearly always "a pull request already exists
             * for this branch" — the branch was force-updated onto an earlier
             * attempt's pull request, which now carries the new commit.
             */
            $existing = $this->github->openPullRequestForBranch($token, $repo, $branch);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * The pull request title, from the error rather than from the diff.
     */
    protected function title(FixJob $job): string
    {
        if ($job->type === FixJobType::Custom) {
            $request = $this->oneLine((string) $job->instructions);

            return Str::limit($request === '' ? 'Requested repository change' : $request, self::TITLE_LIMIT);
        }

        $context = $job->error_context ?? [];
        $exception = $this->string($context, 'exception');
        $message = $this->string($context, 'message');

        $subject = $exception !== '' ? class_basename($exception) : 'production error';
        $title = 'Fix '.$subject;

        if ($message !== '') {
            $title .= ': '.$message;
        }

        return Str::limit($this->oneLine($title), self::TITLE_LIMIT);
    }

    /**
     * Flatten a value onto one line, which is all a title or a subject is.
     */
    protected function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * The commit message pushed to the branch.
     */
    protected function commitMessage(FixJob $job, string $title): string
    {
        $lines = [
            $title,
            '',
            'Machine-generated by Bilis autofix.',
        ];

        if ($job->type === FixJobType::Custom) {
            $lines[] = 'Requested by a team member.';
        } else {
            $lines[] = 'Fingerprint: '.mb_substr((string) $job->fingerprint, 0, 16);
        }

        $lines[] = 'Fix job: '.$job->uuid;

        return implode("\n", $lines);
    }

    /**
     * The pull request body a human has to review the change from.
     */
    protected function body(FixJob $job, AppliedDiff $applied): string
    {
        $repository = $job->repository;

        $lines = [
            '> **Machine-generated — review carefully.** '.($job->type === FixJobType::Custom ? 'A coding agent wrote this change from a team member\'s request. Nobody has read it yet' : 'A coding agent wrote this change from a production error report. Nobody has read it yet').'. Review it as you would a patch from a stranger, and do not merge it because CI is green.',
            '',
            ...($job->type === FixJobType::Custom ? $this->requestSection($job) : $this->errorSection($job)),
            '',
            '## What the agent changed',
            '',
            $this->summary($job),
            '',
            '**Files touched**',
            '',
        ];

        foreach ($applied->paths() as $path) {
            $lines[] = '- `'.$path.'`';
        }

        $lines[] = '';
        $lines[] = '## Verification';
        $lines[] = '';
        $lines[] = $this->verification($job);
        $lines[] = '';
        $lines[] = '## Links';
        $lines[] = '';

        foreach ($this->links($job) as $link) {
            $lines[] = '- '.$link;
        }

        $lines[] = '';
        $lines[] = sprintf(
            '<sub>Bilis autofix · job `%s` · based on `%s` of `%s`</sub>',
            $job->uuid,
            mb_substr($applied->headSha, 0, 7),
            $repository->repo_full_name,
        );

        return implode("\n", $lines);
    }

    /**
     * The error the job was raised for, as a table a reviewer can scan.
     *
     * @return list<string>
     */
    protected function errorSection(FixJob $job): array
    {
        $context = $job->error_context ?? [];

        return [
            '## The error',
            '',
            '| | |',
            '| --- | --- |',
            '| Exception | `'.$this->orDash($this->string($context, 'exception')).'` |',
            '| Message | '.$this->orDash($this->string($context, 'message')).' |',
            '| Service | '.$this->orDash($this->string($context, 'service_name')).' |',
            '| Occurrences | '.$this->orDash((string) ($this->integer($context, 'count') ?: '')).' |',
            '| First seen | '.$this->orDash($this->timestamp($context, 'first_seen')).' |',
            '| Last seen | '.$this->orDash($this->timestamp($context, 'last_seen')).' |',
            '| Fingerprint | `'.mb_substr((string) $job->fingerprint, 0, 16).'` |',
        ];
    }

    /**
     * What a person asked for, quoted rather than paraphrased.
     *
     * A reviewer's first question about a custom pull request is "who asked
     * for this, and in what words" — so the request goes in verbatim, in a
     * fenced block that cannot smuggle markdown into the rest of the body.
     *
     * @return list<string>
     */
    protected function requestSection(FixJob $job): array
    {
        $request = trim((string) $job->instructions);

        return [
            '## The request',
            '',
            'A member of the team asked for this from the Bilis autofix page. No production error is involved.',
            '',
            '```text',
            $request === '' ? '(no request was recorded)' : Str::limit($request, self::REQUEST_EXCERPT_LIMIT),
            '```',
        ];
    }

    /**
     * The agent's own explanation of the change.
     */
    protected function summary(FixJob $job): string
    {
        $report = $job->report;
        $summary = is_array($report) ? ($report['summary'] ?? null) : null;

        return is_string($summary) && trim($summary) !== ''
            ? trim($summary)
            : '_The agent produced no summary._';
    }

    /**
     * What the agent's own test run reported, if anything ran at all.
     */
    protected function verification(FixJob $job): string
    {
        $repository = $job->repository;
        $report = $job->report;
        $tests = is_array($report) && is_array($report['tests'] ?? null) ? $report['tests'] : null;

        if ($repository->test_cmd === null || trim((string) $repository->test_cmd) === '') {
            return 'No test command is configured for this repository, so the agent ran none. This branch has been verified by nothing but CI.';
        }

        if ($tests === null || ! array_key_exists('passed', $tests)) {
            return sprintf('`%s` was configured but the agent reported no result for it.', $repository->test_cmd);
        }

        return $tests['passed'] === true
            ? sprintf('`%s` passed in the agent sandbox. That is not your CI — treat it as a smoke test.', $repository->test_cmd)
            : sprintf('`%s` did **not** pass in the agent sandbox.', $repository->test_cmd);
    }

    /**
     * The deep links that let a reviewer see the error for themselves.
     *
     * @return list<string>
     */
    protected function links(FixJob $job): array
    {
        $report = $job->report;
        $reported = is_array($report) ? ($report['links'] ?? null) : null;

        $links = [];

        if (is_array($reported)) {
            foreach ($reported as $link) {
                if (is_string($link) && str_starts_with($link, 'http')) {
                    $links[] = $link;
                }
            }
        }

        if ($links === []) {
            $links = $this->taskRenderer->render($job)['links'];
        }

        $label = $job->type === FixJobType::Custom ? 'This job in Bilis' : 'This error in Bilis';

        return array_values(array_map(
            fn (string $link): string => sprintf('[%s](%s)', $label, $link),
            array_unique($links),
        ));
    }

    /**
     * Render an empty value as a dash rather than as nothing.
     */
    protected function orDash(string $value): string
    {
        return $value === '' ? '—' : $value;
    }

    /**
     * Read a string off the error context.
     *
     * @param  array<string, mixed>  $values
     */
    protected function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Read a timestamp off the error context as an ISO 8601 string.
     *
     * @param  array<string, mixed>  $values
     */
    protected function timestamp(array $values, string $key): string
    {
        $value = $this->string($values, $key);

        if ($value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601ZuluString();
        } catch (InvalidFormatException) {
            return $value;
        }
    }

    /**
     * Read an integer off the error context.
     *
     * @param  array<string, mixed>  $values
     */
    protected function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }
}
