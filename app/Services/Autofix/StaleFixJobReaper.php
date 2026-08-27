<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use Illuminate\Support\Carbon;

/**
 * Fails dispatched jobs that Ayos will never answer for.
 *
 * Success on the wire only ever means "queued": if Ayos loses its in-process
 * state (a restart, a crash) after accepting a job, the artifact callback
 * never comes and the job would sit in `dispatched`/`running` forever —
 * holding a concurrency slot and showing a live page that streams nothing.
 * Anything unanswered for the job timeout plus a grace period is declared
 * lost; if Ayos somehow still answers afterwards, the artifact callback's
 * idempotency check ignores it (the job is already terminal).
 */
class StaleFixJobReaper
{
    /**
     * Grace on top of the job timeout before a dispatched job counts as lost.
     */
    public const GRACE_MINUTES = 10;

    /**
     * @return list<FixJob> the jobs that were reaped
     */
    public function reap(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $timeoutSeconds = (int) config('autofix.defaults.timeout_s', 900);
        $deadline = $now->copy()->subSeconds($timeoutSeconds)->subMinutes(self::GRACE_MINUTES);

        $stale = FixJob::query()
            ->whereIn('status', [FixJobStatus::Dispatched, FixJobStatus::Running])
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $deadline)
            ->get();

        $reaped = [];

        foreach ($stale as $job) {
            $job->forceFill([
                'status' => FixJobStatus::Failed,
                'failure_reason' => sprintf(
                    'No artifact from Ayos within %d minutes of dispatch; the job was declared lost.',
                    intdiv($timeoutSeconds, 60) + self::GRACE_MINUTES,
                ),
                'completed_at' => $now,
            ])->save();

            $reaped[] = $job;
        }

        return $reaped;
    }
}
