<?php

namespace App\Http\Controllers\Api;

use App\Enums\FixJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyAyosSignature;
use App\Models\FixJob;
use App\Services\Autofix\FixJobEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives batches of events from a running Ayos job.
 *
 * The stream was inverted with the architecture. Ayos used to serve SSE and the
 * browser connected to it directly; a container run has nothing listening, so
 * it POSTs batches here instead and Bilis is the one that fans them out. That
 * deleted the ring buffer, the stream endpoint, the per-job stream JWT check
 * and the CORS policy from Ayos, and left Bilis holding the transcript it was
 * going to persist anyway.
 *
 * Delivery is best-effort by design, and this endpoint has to behave as though
 * it knows that: batches arrive twice, out of order, or not at all, and the
 * artifact carries the authoritative copy at the end regardless. A gap in the
 * live stream is never a failed job.
 */
class AutofixEventController extends Controller
{
    /**
     * The statuses an event batch may still be recorded against.
     *
     * A job whose artifact has already landed is done being narrated; a late
     * batch for it is a retry of something the artifact already contains.
     */
    private const ACCEPTING_STATUSES = [
        FixJobStatus::Pending,
        FixJobStatus::Dispatched,
        FixJobStatus::Running,
    ];

    /**
     * Append one batch.
     */
    public function store(Request $request, FixJobEventRecorder $recorder): JsonResponse
    {
        /** @var FixJob $job */
        $job = $request->attributes->get(VerifyAyosSignature::JOB_ATTRIBUTE);

        $payload = $request->validate([
            'events' => ['required', 'array', 'max:500'],
            'events.*' => ['array'],
        ]);

        if (! in_array($job->status, self::ACCEPTING_STATUSES, true)) {
            // Not an error. The run is allowed to be mid-flush when its own
            // artifact lands, and retrying that flush must not look like a
            // failure to it.
            return new JsonResponse(['recorded' => 0, 'status' => $job->status->value]);
        }

        $recorded = $recorder->record($job, $payload['events']);

        /*
         * The first event batch is the only signal that the container actually
         * started, so it is what moves the job off `dispatched`. Without it a
         * job that boots slowly is indistinguishable from one that never booted
         * until the reaper's deadline.
         */
        if ($job->status === FixJobStatus::Dispatched) {
            $job->forceFill(['status' => FixJobStatus::Running])->save();
        }

        return new JsonResponse(['recorded' => $recorded], Response::HTTP_ACCEPTED);
    }
}
