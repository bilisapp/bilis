<?php

namespace App\Jobs;

use App\Models\FixJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Validates the diff Ayos produced and, if it holds up, opens the pull request.
 *
 * TODO: phase 3 of specs/autofix-laravel.md. This job is the seam the artifact
 * callback dispatches into; the work itself — `DiffValidator` (empty diff,
 * clean apply against the current default branch head, denylisted paths,
 * `max_diff_lines`, failing tests, binary files) and `PullRequestPublisher`
 * (the only holder of write tokens) — is explicitly out of scope for the
 * dispatch path and lands with the write path.
 *
 * Until then the job is a no-op that records the arrival: a job sits in
 * `validating` and waits for the phase 3 implementation rather than moving on
 * to a GitHub write that nothing has validated.
 */
class ValidateAndPublishFix implements ShouldQueue
{
    use Queueable;

    /**
     * How many times validation is attempted before the job is given up on.
     */
    public int $tries = 3;

    public function __construct(public readonly string $uuid) {}

    /**
     * Note that a diff is waiting to be validated.
     */
    public function handle(): void
    {
        $job = FixJob::query()->where('uuid', $this->uuid)->first();

        if ($job === null) {
            return;
        }

        Log::info('Autofix artifact awaiting validation.', [
            'fix_job' => $job->uuid,
            'fingerprint' => $job->fingerprint,
            'diff_lines' => $job->diff === null ? 0 : substr_count($job->diff, "\n"),
        ]);
    }
}
