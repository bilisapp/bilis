<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Models\FixJob;
use App\Services\Logs\LogQuery;
use Illuminate\Support\Carbon;

/**
 * Checks whether a merged fix actually stopped the error it was written for.
 *
 * This is the part of the loop nothing else in the ecosystem can do: the same
 * system that raised the error owns the logs it appears in, so once the pull
 * request lands the question "did that work?" is a query rather than a guess.
 *
 * It runs hourly, reads the merged job's fingerprint back out of ClickHouse
 * for the window since the merge, and says one of three things: not yet (the
 * deploy has not had time), verified (the error stopped), or the fix did not
 * take (it is still happening well after the merge). Either verdict is posted
 * once as a pull request comment and recorded in `verification`, which is what
 * makes the pass idempotent — a job carrying a verdict is never looked at
 * again, so nobody's pull request collects a second opinion from a robot.
 *
 * A failed verification deliberately leaves the job exactly as it is:
 * `merged`, with its `completed_at` intact, which is precisely the state
 * `FixTriggerService` treats as a regression once the error is seen again.
 *
 * @phpstan-type VerificationRecord array{outcome: string, checked_at: string, window: array{from: string, to: string}, occurrences: int, hours_since_merge: float}
 */
class FixVerificationService
{
    /**
     * The verdict recorded when the error stopped happening.
     */
    public const OUTCOME_VERIFIED = 'verified';

    /**
     * The verdict recorded when the error is still happening.
     */
    public const OUTCOME_FAILED = 'failed';

    /**
     * The scope the one write this loop makes is allowed.
     *
     * A comment needs nothing else — not `contents`, and never `workflows`.
     *
     * @var array<string, string>
     */
    public const COMMENT_PERMISSIONS = ['pull_requests' => 'write'];

    /**
     * How much of the fingerprint is shown to a human.
     */
    public const FINGERPRINT_LENGTH = 16;

    public function __construct(
        private readonly LogQuery $logQuery,
        private readonly ErrorFingerprinter $fingerprinter,
        private readonly GitHubAppTokenService $tokens,
        private readonly GitHubRepositoryClient $github,
    ) {}

    /**
     * Check every merged job that has not been ruled on yet.
     *
     * @return list<FixJob>
     */
    public function verify(?Carbon $now = null): array
    {
        if (config('autofix.enabled') !== true) {
            return [];
        }

        $now = ($now ?? Carbon::now())->clone();
        $handled = [];

        /*
         * Only error jobs. A custom job has no fingerprint, so there is no
         * error rate to watch and nothing a verdict could be about.
         */
        $jobs = FixJob::query()
            ->where('type', FixJobType::Error)
            ->where('status', FixJobStatus::Merged)
            ->whereNull('verified_at')
            ->whereNull('verification')
            ->with(['project.team', 'repository.installation'])
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            if ($this->verifyJob($job, $now) !== null) {
                $handled[] = $job;
            }
        }

        return $handled;
    }

    /**
     * Rule on one merged job, or decide it is too early to say anything.
     *
     * Returns the recorded outcome, or null when the job was left untouched —
     * which is the answer for a job still inside its deploy grace window, one
     * whose error is recurring but not yet past the failure deadline, one
     * ClickHouse could not be asked about, and one whose comment could not be
     * posted. All four are retried by the next pass.
     */
    public function verifyJob(FixJob $job, ?Carbon $now = null): ?string
    {
        if ($job->type !== FixJobType::Error || $job->fingerprint === null) {
            return null;
        }

        $now = ($now ?? Carbon::now())->clone();
        $completedAt = $job->completed_at;
        $mergedAt = $completedAt === null ? null : Carbon::instance($completedAt);

        /*
         * Without a merge time there is no window to ask about, and without a
         * pull request number there is nowhere to report the answer.
         */
        if ($mergedAt === null || $job->pr_number === null) {
            return null;
        }

        $elapsed = abs($mergedAt->diffInHours($now));

        /*
         * Merging is not deploying. Counting occurrences before the fix can
         * possibly be running would fail every job that merged during an
         * ongoing incident.
         */
        if ($elapsed < $this->verifyAfterHours()) {
            return null;
        }

        $occurrences = $this->countOccurrences($job, $mergedAt, $now);

        if ($occurrences === null) {
            return null;
        }

        if ($occurrences === 0) {
            return $this->record($job, self::OUTCOME_VERIFIED, $occurrences, $mergedAt, $now, $elapsed);
        }

        /*
         * The error is still happening, but a few stragglers logged while the
         * deploy rolled out are not evidence of anything. Only a fingerprint
         * still recurring well past the deadline counts as a failed fix.
         */
        if ($elapsed < $this->verifyFailAfterHours()) {
            return null;
        }

        return $this->record($job, self::OUTCOME_FAILED, $occurrences, $mergedAt, $now, $elapsed);
    }

    /**
     * Count post-merge occurrences of this job's fingerprint.
     *
     * The fingerprint is computed in PHP, so the query fetches the project's
     * error rows for the window and they are re-fingerprinted here — the same
     * function that grouped them at trigger time, so the two counts mean the
     * same thing.
     *
     * Returns null when ClickHouse could not answer: a half-read window would
     * under-count, and under-counting declares a broken fix verified.
     */
    protected function countOccurrences(FixJob $job, Carbon $mergedAt, Carbon $now): ?int
    {
        $result = $this->logQuery->errorOccurrences(
            (string) $job->project_id,
            $mergedAt,
            $now,
        );

        if ($result['unavailable']) {
            return null;
        }

        $occurrences = 0;

        foreach ($result['rows'] as $row) {
            if ($this->fingerprinter->fingerprint($row) === $job->fingerprint) {
                $occurrences++;
            }
        }

        return $occurrences;
    }

    /**
     * Post the verdict and persist it.
     *
     * The comment goes first on purpose. A job is marked handled only once its
     * pull request actually carries the answer, so a GitHub outage costs a
     * retry rather than a silently swallowed verdict.
     */
    protected function record(FixJob $job, string $outcome, int $occurrences, Carbon $mergedAt, Carbon $now, float $elapsed): ?string
    {
        /** @var VerificationRecord $verification */
        $verification = [
            'outcome' => $outcome,
            'checked_at' => $now->clone()->utc()->toIso8601ZuluString(),
            'window' => [
                'from' => $mergedAt->clone()->utc()->toIso8601ZuluString(),
                'to' => $now->clone()->utc()->toIso8601ZuluString(),
            ],
            'occurrences' => $occurrences,
            'hours_since_merge' => round($elapsed, 2),
        ];

        if (! $this->comment($job, $outcome, $verification)) {
            return null;
        }

        $job->forceFill([
            'verification' => $verification,
            /*
             * Only a fix that worked is verified. A failed one keeps a null
             * `verified_at` and its merged status, which is what leaves it
             * eligible for the trigger's regression path.
             */
            'verified_at' => $outcome === self::OUTCOME_VERIFIED ? $now->clone() : null,
        ])->save();

        return $outcome;
    }

    /**
     * Post the one comment this loop is allowed to write.
     *
     * @param  VerificationRecord  $verification
     */
    protected function comment(FixJob $job, string $outcome, array $verification): bool
    {
        $repository = $job->repository;
        $repo = trim($repository->repo_full_name, '/');

        try {
            $token = $this->tokens->installationToken($repository->installation, $repo, self::COMMENT_PERMISSIONS);

            $this->github->createIssueComment(
                $token,
                $repo,
                (int) $job->pr_number,
                $this->body($job, $outcome, $verification),
            );
        } catch (GitHubAppException $exception) {
            /*
             * Left unhandled on purpose: the next pass re-reads the window and
             * tries the comment again.
             */
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * The comment body a reviewer reads on the pull request.
     *
     * @param  VerificationRecord  $verification
     */
    protected function body(FixJob $job, string $outcome, array $verification): string
    {
        $fingerprint = mb_substr((string) $job->fingerprint, 0, self::FINGERPRINT_LENGTH);
        $hours = $this->humanHours($verification['hours_since_merge']);
        $window = $verification['window']['from'].' → '.$verification['window']['to'];
        $link = $this->logLink($job, $verification);

        $lines = $outcome === self::OUTCOME_VERIFIED
            ? [
                sprintf(
                    '✅ **Verified in production.** Since this merged %s ago, the error it was written for (fingerprint `%s`) has not recurred — **0 occurrences** in the production logs.',
                    $hours,
                    $fingerprint,
                ),
                '',
                sprintf('Window checked: `%s`.', $window),
            ]
            : [
                sprintf(
                    '⚠️ **The fix did not take.** %s after this merged, the error it was written for (fingerprint `%s`) is still happening: **%d occurrence%s** in the production logs.',
                    ucfirst($hours),
                    $fingerprint,
                    $verification['occurrences'],
                    $verification['occurrences'] === 1 ? '' : 's',
                ),
                '',
                sprintf('Window checked: `%s`.', $window),
                '',
                'The fingerprint stays eligible for another fix attempt. This is the only comment Bilis will leave here.',
            ];

        $lines[] = '';
        $lines[] = sprintf('- [These errors in Bilis](%s)', $link);
        $lines[] = '';
        $lines[] = sprintf('<sub>Bilis autofix · job `%s` · checked automatically.</sub>', $job->uuid);

        return implode("\n", $lines);
    }

    /**
     * The log view showing exactly the window that was checked.
     *
     * @param  VerificationRecord  $verification
     */
    protected function logLink(FixJob $job, array $verification): string
    {
        $project = $job->project;
        $context = $job->error_context ?? [];
        $exception = $context['exception'] ?? null;

        $query = [
            'current_team' => $project->team->slug,
            'project' => $project->slug,
            'from' => $verification['window']['from'],
            'to' => $verification['window']['to'],
        ];

        if (is_string($exception) && trim($exception) !== '') {
            $query['search'] = trim($exception);
        }

        return route('logs.index', $query);
    }

    /**
     * Render an elapsed span the way a person would say it.
     */
    protected function humanHours(float $hours): string
    {
        if ($hours < 48) {
            $rounded = max(1, (int) round($hours));

            return $rounded === 1 ? '1 hour' : $rounded.' hours';
        }

        $days = (int) round($hours / 24);

        return $days.' days';
    }

    /**
     * How long a merged fix is given to reach production before it is judged.
     */
    protected function verifyAfterHours(): float
    {
        return max(0.0, (float) config('autofix.defaults.verify_after_hours', 2));
    }

    /**
     * How long a still-recurring error is tolerated before the fix is called a failure.
     */
    protected function verifyFailAfterHours(): float
    {
        return max($this->verifyAfterHours(), (float) config('autofix.defaults.verify_fail_after_hours', 24));
    }
}
