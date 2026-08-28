<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Models\ProjectRepository;
use App\Services\Logs\LogQuery;
use Illuminate\Support\Carbon;

/**
 * Decides which production errors are worth spending a fix attempt on.
 *
 * Run every five minutes by `autofix:scan`, this is the only place that turns
 * log volume into work. Everything it does is a brake rather than an
 * accelerator: an error has to recur often enough to be real, be new or a
 * regression rather than something already being worked on, and fit inside the
 * repository's concurrency and daily budgets. The failure mode being designed
 * against is a loop that opens the same pull request every five minutes.
 *
 * @phpstan-import-type LogRow from LogQuery
 *
 * @phpstan-type ErrorGroup array{fingerprint: string, count: int, first_seen: string, last_seen: string, row: LogRow, samples: list<array{timestamp: string, severity: string, body: string}>}
 */
class FixTriggerService
{
    /**
     * How far back one scan looks.
     *
     * Comfortably wider than the five minute schedule, so a scan that is
     * skipped or slow does not lose the errors that arrived meanwhile.
     */
    public const LOOKBACK_MINUTES = 60;

    /**
     * How many error rows one repository's scan reads.
     */
    public const SAMPLE_LIMIT = 500;

    /**
     * How many sample log lines are kept per fingerprint.
     */
    public const SAMPLES_PER_GROUP = 5;

    public function __construct(
        private readonly LogQuery $logQuery,
        private readonly ErrorFingerprinter $fingerprinter,
        private readonly FixJobBudget $budgets,
    ) {}

    /**
     * Scan every repository that has opted in.
     *
     * @return list<FixJob>
     */
    public function scan(): array
    {
        if (config('autofix.enabled') !== true) {
            return [];
        }

        $created = [];

        $repositories = ProjectRepository::query()
            ->where('autofix_enabled', true)
            ->with(['project'])
            ->orderBy('id')
            ->get();

        foreach ($repositories as $repository) {
            foreach ($this->scanRepository($repository) as $job) {
                $created[] = $job;
            }
        }

        return $created;
    }

    /**
     * Scan one repository and raise fix jobs for what qualifies.
     *
     * @return list<FixJob>
     */
    public function scanRepository(ProjectRepository $repository, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->clone();

        /*
         * Both budgets are shared with the custom jobs people spawn by hand:
         * what is being rationed is agent runs against one codebase.
         */
        $slots = $this->budgets->availableSlots($repository);
        $budget = $this->budgets->remainingBudget($repository, $now);

        if ($slots <= 0 || $budget <= 0) {
            return [];
        }

        $from = $now->clone()->subMinutes(self::LOOKBACK_MINUTES);

        $result = $this->logQuery->errorSamples(
            [(string) $repository->project_id],
            $from,
            $now,
            self::SAMPLE_LIMIT,
        );

        /*
         * A half-read window would under-count every fingerprint in it, which
         * is exactly the input the thresholds below are meant to protect
         * against. Skipping the pass costs five minutes.
         */
        if ($result['unavailable']) {
            return [];
        }

        $minimum = $this->minimumErrorCount();
        $created = [];

        foreach ($this->aggregate($result['rows']) as $group) {
            if ($group['count'] < $minimum) {
                continue;
            }

            if (! $this->shouldTrigger($repository, $group, $now)) {
                continue;
            }

            $created[] = $this->createJob($repository, $group, $from, $now);

            $slots--;
            $budget--;

            if ($slots <= 0 || $budget <= 0) {
                break;
            }
        }

        return $created;
    }

    /**
     * Group the sampled rows by fingerprint, most frequent first.
     *
     * @param  list<LogRow>  $rows
     * @return list<ErrorGroup>
     */
    protected function aggregate(array $rows): array
    {
        /** @var array<string, ErrorGroup> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $fingerprint = $this->fingerprinter->fingerprint($row);
            $timestamp = $row['timestamp'];

            if (! isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'count' => 0,
                    /*
                     * Rows arrive newest first, so the first row seen is both
                     * the most recent occurrence and the best representative.
                     */
                    'first_seen' => $timestamp,
                    'last_seen' => $timestamp,
                    'row' => $row,
                    'samples' => [],
                ];
            }

            $groups[$fingerprint]['count']++;

            if ($timestamp !== '' && ($groups[$fingerprint]['first_seen'] === '' || $timestamp < $groups[$fingerprint]['first_seen'])) {
                $groups[$fingerprint]['first_seen'] = $timestamp;
            }

            if ($timestamp > $groups[$fingerprint]['last_seen']) {
                $groups[$fingerprint]['last_seen'] = $timestamp;
            }

            if (count($groups[$fingerprint]['samples']) < self::SAMPLES_PER_GROUP) {
                $groups[$fingerprint]['samples'][] = [
                    'timestamp' => $timestamp,
                    'severity' => $row['severityText'],
                    'body' => $row['body'],
                ];
            }
        }

        $ordered = array_values($groups);

        usort($ordered, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $ordered;
    }

    /**
     * Decide whether this fingerprint deserves a new attempt.
     *
     * Unseen fingerprints qualify, and so does a regression — a fingerprint
     * whose last fix was merged and which is happening again anyway. Anything
     * else is either already being worked on or inside its cooldown.
     *
     * @param  ErrorGroup  $group
     */
    protected function shouldTrigger(ProjectRepository $repository, array $group, Carbon $now): bool
    {
        /*
         * Only error jobs carry a fingerprint at all; a custom job's is null
         * and it has no cooldown to be part of. Naming the type says so
         * rather than leaving it to SQL's treatment of NULL comparison.
         */
        $latest = FixJob::query()
            ->where('project_repository_id', $repository->id)
            ->where('type', FixJobType::Error)
            ->where('fingerprint', $group['fingerprint'])
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return true;
        }

        if ($latest->status->isActive()) {
            return false;
        }

        $settled = $latest->completed_at ?? $latest->updated_at ?? $latest->created_at;

        if ($latest->status === FixJobStatus::Merged) {
            /*
             * A regression: the fix shipped and the error came back. Only
             * occurrences recorded after the merge count, or every scan would
             * re-trigger on the errors that led to the fix in the first place.
             */
            return $settled !== null && $this->parse($group['last_seen'])?->greaterThan($settled) === true;
        }

        /*
         * Everything else — rejected, failed, cancelled, timed out — waits out
         * the cooldown. The spec names `rejected`; the others are included
         * deliberately, because a fingerprint whose fix keeps failing would
         * otherwise be re-attempted every five minutes forever.
         */
        return $settled === null || $settled->lessThan($now->clone()->subDays($this->cooldownDays()));
    }

    /**
     * Create the fix job row and queue its dispatch.
     *
     * @param  ErrorGroup  $group
     */
    protected function createJob(ProjectRepository $repository, array $group, Carbon $from, Carbon $to): FixJob
    {
        $job = FixJob::query()->create([
            'project_id' => $repository->project_id,
            'project_repository_id' => $repository->id,
            /*
             * Nobody is here to pick a key, so the scan takes the team's
             * default and pins it — the same thing the new-job dialog does
             * when the person leaves the picker alone.
             */
            'team_llm_credential_id' => $repository->project->team->defaultLlmCredential()?->getKey(),
            'type' => FixJobType::Error,
            'fingerprint' => $group['fingerprint'],
            'error_context' => $this->errorContext($group, $from, $to),
            'base_sha' => '',
            'status' => FixJobStatus::Pending,
        ]);

        DispatchFixJob::dispatch($job->uuid);

        return $job;
    }

    /**
     * Everything the dispatcher and the reviewer need about the error.
     *
     * This is what was sent, frozen at trigger time: the row it came from may
     * have aged out of the retention window long before anyone reads the pull
     * request.
     *
     * @param  ErrorGroup  $group
     * @return array<string, mixed>
     */
    protected function errorContext(array $group, Carbon $from, Carbon $to): array
    {
        $row = $group['row'];

        return [
            'fingerprint' => $group['fingerprint'],
            'service_name' => $this->fingerprinter->serviceName($row),
            'exception' => $this->fingerprinter->exceptionClass($row),
            'message' => $this->fingerprinter->message($row),
            'stack' => $this->fingerprinter->stackTrace($row),
            'frames' => $this->fingerprinter->stackFrames($row),
            'count' => $group['count'],
            'first_seen' => $group['first_seen'],
            'last_seen' => $group['last_seen'],
            'samples' => $group['samples'],
            'window' => [
                'from' => $from->clone()->utc()->toIso8601ZuluString(),
                'to' => $to->clone()->utc()->toIso8601ZuluString(),
            ],
        ];
    }

    /**
     * How many occurrences an error needs before it is worth fixing.
     */
    protected function minimumErrorCount(): int
    {
        return max(1, (int) config('autofix.defaults.min_error_count', 5));
    }

    /**
     * How long a settled fingerprint is left alone for.
     */
    protected function cooldownDays(): int
    {
        return max(0, (int) config('autofix.defaults.cooldown_days', 7));
    }

    /**
     * Parse a ClickHouse timestamp, which is always naive UTC.
     */
    private function parse(string $timestamp): ?Carbon
    {
        if (trim($timestamp) === '') {
            return null;
        }

        return Carbon::parse($timestamp, 'UTC');
    }
}
