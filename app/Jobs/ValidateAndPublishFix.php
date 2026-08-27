<?php

namespace App\Jobs;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use App\Services\Autofix\DiffValidator;
use App\Services\Autofix\GitHubAppException;
use App\Services\Autofix\PullRequestPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Validates the diff Ayos produced and, if it holds up, opens the pull request.
 *
 * This is the only path from an artifact to a GitHub write, and it is ordered
 * so that no write can happen on an unchecked diff: `DiffValidator` runs first
 * and applies the patch in memory against the current default branch head,
 * `PullRequestPublisher` commits exactly what came out of that.
 *
 * Three things make it safe to run twice, which the queue will eventually do:
 * only a job still sitting in `validating` is touched at all, a transient
 * GitHub failure releases the job rather than failing it, and a diff that no
 * longer applies buys one re-dispatch before it is rejected for good.
 */
class ValidateAndPublishFix implements ShouldQueue
{
    use Queueable;

    /**
     * How many times validation is attempted before the job is given up on.
     */
    public int $tries = 3;

    /**
     * The delay, in seconds, before each successive attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public readonly string $uuid) {}

    /**
     * Validate the diff and publish it.
     */
    public function handle(DiffValidator $validator, PullRequestPublisher $publisher): void
    {
        $job = FixJob::query()->where('uuid', $this->uuid)->first();

        /*
         * Anything that is not `validating` has already been decided — by an
         * earlier run of this job, by the webhook, or by a cancellation — and
         * must not be validated or published a second time.
         */
        if ($job === null || $job->status !== FixJobStatus::Validating) {
            return;
        }

        try {
            $result = $validator->validate($job);

            if ($result->isRejected()) {
                if ($result->reason === 'empty_diff') {
                    $this->recordNoChange($job);

                    return;
                }

                $this->reject($job, (string) $result->reason);

                return;
            }

            if ($result->isRedispatch()) {
                $this->redispatch($job, (string) $result->reason);

                return;
            }

            $applied = $result->applied();

            if ($applied === null) {
                $this->recordNoChange($job);

                return;
            }

            $publisher->publish($job, $applied);
        } catch (GitHubAppException $exception) {
            if ($exception->isTransient()) {
                report($exception);

                $this->release($this->delayForAttempt());

                return;
            }

            $this->markFailed($job, $exception->getMessage());
            $this->fail($exception);
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
            $exception?->getMessage() ?? 'The diff could not be validated within the retry budget.',
        );
    }

    /**
     * The agent finished without touching the tree. That is an answer, not a
     * failure: its reasoning is in the transcript, and there is nothing to
     * publish.
     */
    protected function recordNoChange(FixJob $job): void
    {
        $job->forceFill([
            'status' => FixJobStatus::NoChange,
            'failure_reason' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Refuse the diff. No GitHub write has happened and none will.
     */
    protected function reject(FixJob $job, string $reason): void
    {
        $job->forceFill([
            'status' => FixJobStatus::Rejected,
            'failure_reason' => mb_substr($reason, 0, 1000),
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Send the job back around once, against a fresh base commit.
     *
     * The diff and the transcript are cleared: the next run produces its own,
     * and keeping the stale patch on the row would only invite someone to
     * publish it later.
     */
    protected function redispatch(FixJob $job, string $reason): void
    {
        $job->forceFill([
            'status' => FixJobStatus::Pending,
            'redispatch_count' => $job->redispatch_count + 1,
            'diff' => null,
            'report' => null,
            'failure_reason' => mb_substr($reason, 0, 1000),
            'dispatched_at' => null,
        ])->save();

        DispatchFixJob::dispatch($job->uuid);
    }

    /**
     * Move the fix job to its failed terminal state, once.
     */
    protected function markFailed(FixJob $job, string $reason): void
    {
        if ($job->status !== FixJobStatus::Validating) {
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
