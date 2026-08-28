<?php

namespace App\Http\Controllers\Api;

use App\Enums\FixJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyAyosSignature;
use App\Jobs\ValidateAndPublishFix;
use App\Models\FixJob;
use App\Services\Autofix\FixJobEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the artifact Ayos produces at the end of a job.
 *
 * The request is authenticated by `ayos.signature` alone — there is no session
 * and no API key on this path, only an Ed25519 signature over
 * `{timestamp}.{raw body}` made with the key minted for this one run. The
 * middleware has already resolved the job that key belongs to; the payload is
 * re-validated here from scratch regardless.
 *
 * Ayos retries the callback with backoff, so this endpoint has to be
 * idempotent: a job that has already moved past `validating` answers 200 and
 * changes nothing. It never opens a pull request itself — a `done` artifact
 * only moves the job to `validating` and queues the validator, so no GitHub
 * write can happen on a diff nothing has checked.
 */
class AutofixArtifactController extends Controller
{
    /**
     * The statuses Ayos reports, mapped onto the fix job lifecycle.
     *
     * `done` deliberately does not mean finished: the agent produced a diff,
     * which is the start of the validation path rather than the end of the job.
     *
     * @var array<string, FixJobStatus>
     */
    private const STATUS_MAP = [
        'done' => FixJobStatus::Validating,
        'failed' => FixJobStatus::Failed,
        'cancelled' => FixJobStatus::Cancelled,
        'timeout' => FixJobStatus::Timeout,
    ];

    /**
     * The fix job statuses an artifact may still be applied to.
     *
     * Anything further along has already been handled — by an earlier delivery
     * of this same artifact, or by the write path.
     *
     * @var array<int, FixJobStatus>
     */
    private const ACCEPTING_STATUSES = [
        FixJobStatus::Pending,
        FixJobStatus::Dispatched,
        FixJobStatus::Running,
    ];

    /**
     * Persist one artifact.
     */
    public function store(Request $request, FixJobEventRecorder $recorder): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->validate([
            'job_id' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(self::STATUS_MAP))],
            'diff' => ['nullable', 'string'],
            'report' => ['nullable', 'array'],
            'events' => ['nullable', 'array'],
        ]);

        /*
         * The job the signature was verified against — not a second lookup by
         * the id in the body. Those are the same row by construction, and
         * taking it from the middleware is what makes that guaranteed rather
         * than merely true.
         */
        /** @var FixJob $job */
        $job = $request->attributes->get(VerifyAyosSignature::JOB_ATTRIBUTE);

        if (! in_array($job->status, self::ACCEPTING_STATUSES, true)) {
            return new JsonResponse([
                'status' => $job->status->value,
                'applied' => false,
            ]);
        }

        $status = self::STATUS_MAP[(string) $payload['status']];

        /*
         * The artifact's transcript is MERGED into whatever the live batches
         * already left, never assigned over it. The artifact's copy is the
         * authoritative one, but assigning it would also mean that an artifact
         * arriving without events — or with a truncated tail — erased a
         * transcript the viewer had been watching all along.
         */
        if (is_array($payload['events'] ?? null)) {
            $recorder->record($job, $payload['events']);
        }

        $attributes = [
            'status' => $status,
            'diff' => is_string($payload['diff'] ?? null) ? $payload['diff'] : null,
            'report' => is_array($payload['report'] ?? null) ? $payload['report'] : null,
        ];

        if ($status->isTerminal()) {
            $attributes['completed_at'] = now();
            $attributes['failure_reason'] = $this->failureReason($status, $attributes['report']);
        }

        $job->forceFill($attributes)->save();

        if ($status === FixJobStatus::Validating) {
            ValidateAndPublishFix::dispatch($job->uuid);
        }

        return new JsonResponse([
            'status' => $job->status->value,
            'applied' => true,
        ]);
    }

    /**
     * Describe why the job came to rest, preferring the agent's own words.
     *
     * @param  array<string, mixed>|null  $report
     */
    private function failureReason(FixJobStatus $status, ?array $report): string
    {
        // Ayos separates the failure itself from the agent's narration; the
        // banner should name the failure, not the last thing the agent said.
        $error = $report['error'] ?? null;

        if (is_string($error) && trim($error) !== '') {
            return mb_substr(trim($error), 0, 1000);
        }

        $summary = $report['summary'] ?? null;

        if (is_string($summary) && trim($summary) !== '') {
            return mb_substr(trim($summary), 0, 1000);
        }

        return match ($status) {
            FixJobStatus::Cancelled => 'The job was cancelled before it produced a diff.',
            FixJobStatus::Timeout => 'The agent ran past its wall clock budget.',
            default => 'Ayos reported the job failed.',
        };
    }
}
