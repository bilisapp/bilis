<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\OtlpLogMapper;
use App\Services\Ingest\OtlpResponse;
use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\Protobuf\OtlpProtobufDecoder;
use App\Services\Ingest\RequestBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OTLP/HTTP JSON logs endpoint.
 *
 * Ingestion is deliberately forgiving: records that cannot be mapped are
 * reported through OTLP's partial success response instead of failing the
 * whole export, so a misbehaving client never loses its healthy records.
 */
class OtlpLogController extends Controller
{
    /**
     * The number of seconds clients should wait before retrying a rejection.
     */
    private const RETRY_AFTER_SECONDS = 5;

    /**
     * Content types carrying the protobuf encoding, which v1 cannot decode.
     *
     * @var array<int, string>
     */
    private const PROTOBUF_CONTENT_TYPES = ['application/x-protobuf', 'application/protobuf'];

    public function __construct(
        private readonly OtlpLogMapper $mapper,
        private readonly LogWriter $writer,
        private readonly OtlpProtobufDecoder $protobuf,
        private readonly OtlpResponse $responses,
    ) {}

    /**
     * Accept an OTLP `ExportLogsServiceRequest`.
     */
    public function store(Request $request): Response
    {
        $protobuf = $this->isProtobuf($request);

        if ($protobuf && ! $this->protobufIsEnabled()) {
            return new JsonResponse([
                'message' => 'Only the OTLP JSON encoding is supported. Set OTEL_EXPORTER_OTLP_PROTOCOL=http/json and send Content-Type: application/json.',
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $encoding = RequestBody::encoding($request);

        if (! RequestBody::isSupportedEncoding($encoding)) {
            return $this->unsupportedEncoding($encoding);
        }

        $project = AuthenticateProjectApiKey::project($request);

        if ($project === null) {
            return new JsonResponse(['message' => 'API key invalid.'], Response::HTTP_UNAUTHORIZED);
        }

        $mapped = $this->mapper->map($this->decode($request, $protobuf), (string) $project->id);

        if ($mapped->rows !== []) {
            try {
                $this->writer->write($mapped->rows);
            } catch (ClickHouseException $exception) {
                return $this->unavailable($exception);
            }
        }

        if (! $mapped->hasRejections()) {
            return $this->responses->success($protobuf);
        }

        return $this->responses->partialSuccess(
            $protobuf,
            'rejectedLogRecords',
            $mapped->rejected,
            $mapped->errorMessage ?? 'Some log records could not be parsed and were skipped.',
        );
    }

    /**
     * Decode the request body into an OTLP export request array.
     *
     * Both encodings land on the same array shape, so the mapper never learns
     * which one arrived. Anything unreadable — a truncated protobuf message,
     * a body that is not JSON, a gzip stream that will not inflate — comes
     * back as null, which the mapper reports as a rejected payload rather than
     * a client error.
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
            return $this->protobuf->decodeLogs($body);
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
     * Whether the protobuf encoding is accepted by this instance.
     */
    private function protobufIsEnabled(): bool
    {
        return (bool) config('bilis.ingest.otlp_protobuf');
    }

    /**
     * Refuse a compression this application cannot undo.
     *
     * Unlike a bad payload, this is worth a `4xx`: no amount of retrying makes
     * a zstd body readable, and the exporter's own configuration is the fix.
     */
    private function unsupportedEncoding(string $encoding): JsonResponse
    {
        return new JsonResponse([
            'message' => "Content-Encoding {$encoding} is not supported. Send the body uncompressed or with gzip or deflate.",
        ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    /**
     * Determine whether the client sent the protobuf encoding.
     */
    private function isProtobuf(Request $request): bool
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        foreach (self::PROTOBUF_CONTENT_TYPES as $protobuf) {
            if (str_contains($contentType, $protobuf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate a ClickHouse failure into a retryable 503.
     */
    private function unavailable(ClickHouseException $exception): JsonResponse
    {
        Log::error('Failed to write OTLP log records to ClickHouse.', [
            'overload' => $exception->isOverload(),
            'exception' => $exception,
        ]);

        return new JsonResponse(
            ['message' => 'Log storage is temporarily unavailable. Please retry.'],
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => (string) self::RETRY_AFTER_SECONDS],
        );
    }
}
