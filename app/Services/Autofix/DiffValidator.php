<?php

namespace App\Services\Autofix;

use App\Models\FixJob;
use App\Models\ProjectRepository;
use Illuminate\Support\Str;

/**
 * The gate between an agent's patch and a GitHub write.
 *
 * Nothing the agent says is taken on trust here. Ayos was told about the path
 * denylist, the line budget and the test command, and every one of those is
 * checked again on this side — a prompt is not an access control, and the
 * `.github/**` rule in particular is an invariant of the whole feature rather
 * than a setting.
 *
 * The last rule is the expensive one: the diff is actually applied, in memory,
 * against the current head of the default branch. That doubles as the write
 * path's first half — the publisher commits exactly the content this produced,
 * so validation and publication can never disagree about what the patch means.
 */
class DiffValidator
{
    /**
     * The permissions the validator reads the repository with.
     *
     * Read only. The write token is minted later and only by the publisher.
     *
     * @var array<string, string>
     */
    public const READ_PERMISSIONS = ['contents' => 'read'];

    /**
     * The paths no configuration can un-deny.
     *
     * @var list<string>
     */
    public const ALWAYS_DENIED = ['.github/**', '.env*'];

    /**
     * How many times a job may be re-dispatched for a stale base commit.
     */
    public const REDISPATCH_LIMIT = 1;

    public function __construct(
        private readonly GitHubAppTokenService $tokens,
        private readonly GitHubRepositoryClient $github,
        private readonly UnifiedDiffParser $parser,
        private readonly DiffApplier $applier,
    ) {}

    /**
     * Decide what should happen to a job's diff.
     *
     * @throws GitHubAppException
     */
    public function validate(FixJob $job): DiffValidationResult
    {
        $diff = $job->diff;

        if (! is_string($diff) || trim($diff) === '') {
            return DiffValidationResult::rejected('empty_diff');
        }

        try {
            $files = $this->parser->parse($diff);
        } catch (DiffParseException $exception) {
            return DiffValidationResult::rejected('unreadable_diff: '.$exception->getMessage());
        }

        $repository = $job->repository;

        foreach ($files as $file) {
            if ($file->isBinary) {
                return DiffValidationResult::rejected('binary_change: '.($file->newPath ?? $file->oldPath ?? 'unknown path'));
            }

            foreach ($file->paths() as $path) {
                $normalized = $this->normalize($path);

                if ($normalized === null) {
                    return DiffValidationResult::rejected('path_traversal: '.$path);
                }

                if ($this->isDenied($normalized)) {
                    return DiffValidationResult::rejected('denylisted_path: '.$normalized);
                }
            }
        }

        $changed = array_sum(array_map(fn (DiffFile $file): int => $file->changedLines(), $files));
        $limit = $this->maxDiffLines();

        if ($changed > $limit) {
            return DiffValidationResult::rejected(sprintf('diff_too_large: %d changed lines, limit %d', $changed, $limit));
        }

        if ($this->testsFailed($job, $repository)) {
            return DiffValidationResult::rejected('tests_failed');
        }

        try {
            $applied = $this->apply($job, $repository, $files);
        } catch (DiffApplyException $exception) {
            if ($job->redispatch_count < self::REDISPATCH_LIMIT) {
                return DiffValidationResult::redispatch('stale_base: '.$exception->getMessage());
            }

            return DiffValidationResult::rejected('diff_does_not_apply: '.$exception->getMessage());
        }

        return DiffValidationResult::valid($applied);
    }

    /**
     * Apply every file of the diff against the current default branch head.
     *
     * @param  list<DiffFile>  $files
     *
     * @throws DiffApplyException
     * @throws GitHubAppException
     */
    protected function apply(FixJob $job, ProjectRepository $repository, array $files): AppliedDiff
    {
        $token = $this->tokens->installationToken(
            $repository->installation,
            $repository->repo_full_name,
            self::READ_PERMISSIONS,
        );

        $repo = trim($repository->repo_full_name, '/');
        $head = $this->github->headSha($token, $repo, $repository->default_branch);
        $tree = $this->github->tree($token, $repo, $head);

        /** @var list<AppliedChange> $changes */
        $changes = [];

        foreach ($files as $file) {
            $source = $file->isNew ? null : $file->oldPath;
            $original = $source === null ? null : $this->content($token, $repo, $head, $source, $tree);

            $content = $this->applier->apply($original, $file);

            if ($file->isDeleted || $file->newPath === null) {
                $changes[] = new AppliedChange((string) $file->oldPath, null, $this->mode($file, (string) $file->oldPath, $tree));

                continue;
            }

            $changes[] = new AppliedChange($file->newPath, $content ?? '', $this->mode($file, $file->newPath, $tree));

            if ($file->isRename && $file->oldPath !== null && $file->oldPath !== $file->newPath) {
                $changes[] = new AppliedChange($file->oldPath, null, $this->mode($file, $file->oldPath, $tree));
            }
        }

        return new AppliedDiff($head, $tree['sha'], $changes);
    }

    /**
     * Read a file's current content, whatever the tree listing knows about it.
     *
     * @param  array{sha: string, truncated: bool, entries: array<string, array{sha: string, mode: string}>}  $tree
     *
     * @throws GitHubAppException
     */
    protected function content(string $token, string $repo, string $head, string $path, array $tree): ?string
    {
        $entry = $tree['entries'][$path] ?? null;

        if ($entry !== null) {
            return $this->github->blob($token, $repo, $entry['sha']);
        }

        /*
         * A tree GitHub truncated does not prove the file is missing, so the
         * contents API is asked before the diff is called stale.
         */
        if ($tree['truncated']) {
            return $this->github->fileContent($token, $repo, $path, $head);
        }

        return null;
    }

    /**
     * The file mode a change should be written with.
     *
     * The diff's own `new file mode` / `new mode` wins, then whatever the
     * repository already had — an executable script stays executable.
     *
     * @param  array{sha: string, truncated: bool, entries: array<string, array{sha: string, mode: string}>}  $tree
     */
    protected function mode(DiffFile $file, string $path, array $tree): string
    {
        if (is_string($file->mode) && $file->mode !== '') {
            return $file->mode;
        }

        $existing = $tree['entries'][$path]['mode']
            ?? ($file->oldPath === null ? null : ($tree['entries'][$file->oldPath]['mode'] ?? null));

        return is_string($existing) ? $existing : PullRequestPublisher::DEFAULT_FILE_MODE;
    }

    /**
     * Determine whether the agent's own test run failed.
     *
     * Only meaningful when a test command was configured: with no `test_cmd`
     * there was nothing to run, and the pull request's CI is the check.
     */
    protected function testsFailed(FixJob $job, ProjectRepository $repository): bool
    {
        if ($repository->test_cmd === null || trim($repository->test_cmd) === '') {
            return false;
        }

        $report = $job->report;

        if (! is_array($report) || ! is_array($report['tests'] ?? null)) {
            return false;
        }

        return ($report['tests']['passed'] ?? null) === false;
    }

    /**
     * Determine whether a path is off limits.
     */
    protected function isDenied(string $path): bool
    {
        foreach ($this->denylist() as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }

            $prefix = rtrim($pattern, '*/');

            if ($prefix !== '' && $prefix !== $pattern && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The paths a diff may not touch.
     *
     * @return list<string>
     */
    protected function denylist(): array
    {
        $configured = config('autofix.defaults.path_denylist', []);

        $patterns = self::ALWAYS_DENIED;

        if (is_array($configured)) {
            foreach ($configured as $pattern) {
                if (is_string($pattern) && trim($pattern) !== '') {
                    $patterns[] = trim($pattern);
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Normalise a path, or refuse it for trying to leave the repository.
     */
    protected function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * The configured ceiling on how much a diff may change.
     */
    protected function maxDiffLines(): int
    {
        $limit = config('autofix.defaults.max_diff_lines', 800);

        return is_numeric($limit) ? (int) $limit : 800;
    }
}
