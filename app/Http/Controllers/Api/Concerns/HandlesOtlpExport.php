<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\MappedLogs;
use App\Services\Ingest\MappedMetrics;
use App\Services\Ingest\MappedSpans;
use App\Services\Ingest\OtlpResponse;
use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\RequestBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One OTLP/HTTP export, whatever the signal: negotiate the encoding, inflate
 * the body, decode it, map it for the authenticated project, write it, and
 * answer the way the OTLP spec asks.
 *
 * Logs, traces and metrics differ only in their decoder, mapper and writer and
 * in the words they use, which each controller supplies. Everything that must
 * hold for all three lives here once (`.ai/rules/ingest.md`):
 *
 * - a payload that cannot be read is a partial success, never a 400 (R8);
 * - a compression we cannot undo, or protobuf on an instance that turned it
 *   off, is a 415 — no retry fixes it, so the exporter's config must;
 * - any ClickHouse failure is a 503 with `Retry-After`, never the client's fault;
 * - the response is in the encoding the request came in.
 *
 * OTLP over gRPC is not supported, for any signal. Collectors default to gRPC
 * on 4317, which makes it the most likely reason a new user thinks Bilis is
 * broken; the ingestion docs say so up front.
 */
trait HandlesOtlpExport
{
    /**
     * Decode a protobuf body into the array shape `json_decode` would give.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    abstract protected function decodeProtobuf(string $body): array;

    /**
     * Map a decoded export for the given project.
     */
    abstract protected function mapExport(mixed $payload, string $projectId): MappedLogs|MappedSpans|MappedMetrics;

    /**
     * Queue the mapped rows in ClickHouse.
     *
     * @param  array<array-key, mixed>  $rows
     *
     * @throws ClickHouseException
     */
    abstract protected function writeRows(array $rows): void;

    /**
     * The words this signal answers with.
     *
     * @return array{rejectedField: string, rejectedMessage: string, logMessage: string, unavailableMessage: string}
     */
    abstract protected function signal(): array;

    /**
     * Accept one OTLP export request.
     */
    public function store(Request $request): Response
    {
        $protobuf = $this->isProtobuf($request);

        if ($protobuf && ! (bool) config('bilis.ingest.otlp_protobuf')) {
            return new JsonResponse([
                'message' => 'Only the OTLP JSON encoding is supported. Set OTEL_EXPORTER_OTLP_PROTOCOL=http/json and send Content-Type: application/json.',
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $encoding = RequestBody::encoding($request);

        if (! RequestBody::isSupportedEncoding($encoding)) {
            return new JsonResponse([
                'message' => "Content-Encoding {$encoding} is not supported. Send the body uncompressed or with gzip or deflate.",
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $project = AuthenticateProjectApiKey::project($request);

        if ($project === null) {
            return new JsonResponse(['message' => 'API key invalid.'], Response::HTTP_UNAUTHORIZED);
        }

        $mapped = $this->mapExport($this->decode($request, $protobuf), (string) $project->id);
        $signal = $this->signal();

        if ($mapped->rows !== []) {
            try {
                $this->writeRows($mapped->rows);
            } catch (ClickHouseException $exception) {
                Log::error($signal['logMessage'], [
                    'overload' => $exception->isOverload(),
                    'exception' => $exception,
                ]);

                return new JsonResponse(
                    ['message' => $signal['unavailableMessage']],
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    ['Retry-After' => '5'],
                );
            }
        }

        $responses = app(OtlpResponse::class);

        if (! $mapped->hasRejections()) {
            return $responses->success($protobuf);
        }

        return $responses->partialSuccess(
            $protobuf,
            $signal['rejectedField'],
            $mapped->rejected,
            $mapped->errorMessage ?? $signal['rejectedMessage'],
        );
    }

    /**
     * Decode the request body into an OTLP export request array.
     *
     * Both encodings land on the same array shape, so the mapper never learns
     * which one arrived. Anything unreadable — a truncated protobuf message, a
     * body that is not JSON, a gzip stream that will not inflate — comes back
     * as null, which the mapper reports as a rejected payload rather than a
     * client error.
     */
    private function decode(Request $request, bool $protobuf): mixed
    {
        $body = RequestBody::read($request);

        if ($body === null) {
            return null;
        }

        if (! $protobuf) {
            $decoded = json_decode($body, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        try {
            return $this->decodeProtobuf($body);
        } catch (MalformedProtobufException) {
            /*
             * Deliberately not logged: the answer already tells the client its
             * body could not be read, and a log line per malformed request is
             * a write amplifier anyone can pull on an ingest endpoint.
             */
            return null;
        }
    }

    /**
     * Whether the client sent the protobuf encoding.
     */
    private function isProtobuf(Request $request): bool
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        return str_contains($contentType, 'application/x-protobuf')
            || str_contains($contentType, 'application/protobuf');
    }
}
