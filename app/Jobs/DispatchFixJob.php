<?php

namespace App\Jobs;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use App\Services\Autofix\AyosClient;
use App\Services\Autofix\AyosException;
use App\Services\Autofix\GitHubAppException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hands one pending fix job to Ayos.
 *
 * Three outcomes matter and they are deliberately not the same thing:
 *
 * - **Accepted.** The job becomes `dispatched` and Ayos owns it from here.
 * - **Backpressure.** Ayos answers `429` because it is already at capacity.
 *   It never holds a backlog, so the control plane does: the fix job stays
 *   `pending`, untouched, and this queued job releases itself with a growing
 *   delay. Nothing failed.
 * - **Failure.** A transient one (connection failure, 5xx, a rate limited
 *   GitHub) is retried the same way; anything else fails the fix job outright
 *   with the reason recorded, because retrying a rejected job spec forever
 *   only hides the misconfiguration behind it.
 */
class DispatchFixJob implements ShouldQueue
{
    use Queueable;

    /**
     * How many times dispatch is attempted before the job is given up on.
     *
     * Both backpressure and transient failures consume an attempt, so this is
     * a ceiling on the whole conversation rather than on failures alone.
     */
    public int $tries = 8;

    /**
     * The delay, in seconds, before each successive attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function __construct(public readonly string $uuid) {}

    /**
     * Offer the fix job to Ayos.
     */
    public function handle(AyosClient $ayos): void
    {
        $job = FixJob::query()->where('uuid', $this->uuid)->first();

        /*
         * A job that is no longer pending has already been dispatched, or was
         * cancelled while this attempt sat in the queue. Either way it must not
         * be handed to Ayos a second time.
         */
        if ($job === null || $job->status !== FixJobStatus::Pending) {
            return;
        }

        try {
            $ayos->dispatch($job);
        } catch (AyosException|GitHubAppException $exception) {
            if (! $exception->isTransient()) {
                $this->markFailed($job, $exception->getMessage());
                $this->fail($exception);

                return;
            }

            if (! ($exception instanceof AyosException && $exception->isBackpressure())) {
                report($exception);
            }

            /*
             * Still pending, still ours. The release keeps the fix job exactly
             * as it was, which is what makes a 429 backpressure rather than a
             * failure.
             */
            $this->release($this->delayForAttempt());
        }
    }

    /**
     * Record the failure once the queue has given up on the job.
     */
    public function failed(?Throwable $exception): void
    {
        $job = FixJob::query()->where('uuid', $this->uuid)->first();

        if ($job === null) {
            return;
        }

        $this->markFailed(
            $job,
            $exception?->getMessage() ?? 'Ayos did not accept the job within the retry budget.',
        );
    }

    /**
     * Move the fix job to its failed terminal state, once.
     */
    protected function markFailed(FixJob $job, string $reason): void
    {
        if ($job->status !== FixJobStatus::Pending) {
            return;
        }

        $job->forceFill([
            'status' => FixJobStatus::Failed,
            'failure_reason' => mb_substr($reason, 0, 1000),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * The backoff delay for the attempt that has just been made.
     */
    protected function delayForAttempt(): int
    {
        $backoff = $this->backoff();
        $index = max(0, $this->attempts() - 1);

        return $backoff[min($index, count($backoff) - 1)];
    }
}
