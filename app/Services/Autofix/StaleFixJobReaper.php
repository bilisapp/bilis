<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Fails dispatched jobs that no run will ever answer for.
 *
 * A run can die without saying anything: killed mid-package, out of memory, or
 * simply unable to reach the callback. The job would otherwise sit in
 * `dispatched`/`running` forever, showing a live page that streams nothing.
 *
 * Two signals, and both are needed.
 *
 * **The run's own status** is decisive and fast. A run the platform reports as
 * finished, which has not delivered an artifact, is a lost job right now — no
 * waiting required. This is the signal that only exists because Ayos stopped
 * being a service: there was nothing to ask about an in-flight job before.
 *
 * **The deadline** is the backstop for everything the first signal cannot see —
 * a driver that cannot answer, a platform that is down, a job that was never
 * given a run id. Anything unanswered for the job timeout plus a grace period
 * is declared lost regardless.
 *
 * If a run somehow answers afterwards, the artifact callback's idempotency
 * check ignores it: the job is already terminal.
 *
 * The two queue-owned states are reaped too. `pending` and `validating` both
 * count against the repository's `max_concurrent`, so a DispatchFixJob or
 * ValidateAndPublishFix lost from the queue (a flushed Redis, a worker killed
 * past its retries) would otherwise block that repository's Autofix for good.
 * Neither has a run to ask, so only a deadline applies — measured from the
 * job's last write, and long enough to outlast every retry and backoff.
 */
class StaleFixJobReaper
{
    /**
     * Grace on top of the job timeout before a dispatched job counts as lost.
     */
    public const GRACE_MINUTES = 10;

    /**
     * How long a run is given to actually start before its status is believed.
     *
     * A run that has been accepted but not yet scheduled can report as finished
     * for a moment on some platforms, and reaping a job that is about to start
     * is worse than reaping one a minute late.
     */
    public const STATUS_GRACE_SECONDS = 60;

    /**
     * How long a job may sit in `pending` or `validating` untouched.
     *
     * DispatchFixJob's eight tries and backoff span about 40 minutes and
     * ValidateAndPublishFix's about 8, so two hours is only ever a lost job.
     */
    public const QUEUED_DEADLINE_MINUTES = 120;

    public function __construct(private readonly AyosClient $ayos) {}

    /**
     * @return list<FixJob> the jobs that were reaped
     */
    public function reap(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $timeoutSeconds = (int) config('autofix.defaults.timeout_s', 900);
        $deadline = $now->copy()->subSeconds($timeoutSeconds)->subMinutes(self::GRACE_MINUTES);

        $inFlight = FixJob::query()
            ->whereIn('status', [FixJobStatus::Dispatched, FixJobStatus::Running])
            ->whereNotNull('dispatched_at')
            ->get();

        $reaped = [];

        foreach ($inFlight as $job) {
            $reason = $this->lostReason($job, $now, $deadline, $timeoutSeconds);

            if ($reason === null) {
                continue;
            }

            $job->forceFill([
                'status' => FixJobStatus::Failed,
                'failure_reason' => mb_substr($reason, 0, FixJob::MAX_FAILURE_REASON),
                'completed_at' => $now,
            ])->save();

            $reaped[] = $job;
        }

        $queuedDeadline = $now->copy()->subMinutes(self::QUEUED_DEADLINE_MINUTES);

        $stuck = FixJob::query()
            ->whereIn('status', [FixJobStatus::Pending, FixJobStatus::Validating])
            ->where('updated_at', '<', $queuedDeadline)
            ->get();

        foreach ($stuck as $job) {
            $job->forceFill([
                'status' => FixJobStatus::Failed,
                'failure_reason' => $job->status === FixJobStatus::Pending
                    ? sprintf('The job was never handed to Ayos within %d minutes; it was declared lost.', self::QUEUED_DEADLINE_MINUTES)
                    : sprintf('Validating the diff did not finish within %d minutes; the job was declared lost.', self::QUEUED_DEADLINE_MINUTES),
                'completed_at' => $now,
            ])->save();

            $reaped[] = $job;
        }

        return $reaped;
    }

    /**
     * Why this job is lost, or null if it is simply still working.
     */
    protected function lostReason(FixJob $job, Carbon $now, Carbon $deadline, int $timeoutSeconds): ?string
    {
        if ($job->dispatched_at !== null && $job->dispatched_at->lt($deadline)) {
            return sprintf(
                'No artifact from Ayos within %d minutes of dispatch; the job was declared lost.',
                intdiv($timeoutSeconds, 60) + self::GRACE_MINUTES,
            );
        }

        if ($job->dispatched_at === null
            || $job->dispatched_at->gt($now->copy()->subSeconds(self::STATUS_GRACE_SECONDS))) {
            return null;
        }

        try {
            $status = $this->ayos->runStatus($job);
        } catch (Throwable $exception) {
            /*
             * The platform could not be asked. That is not evidence the run is
             * dead — the deadline above remains the answer, and reaping on a
             * failed status call would turn a Scaleway blip into a wave of
             * failed jobs.
             */
            report($exception);

            return null;
        }

        if ($status === null || $status->isAlive()) {
            return null;
        }

        return 'The Ayos run ended without delivering an artifact; the job was declared lost.';
    }
}
