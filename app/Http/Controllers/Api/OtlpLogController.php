<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\OtlpLogMapper;
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
    ) {}

    /**
     * Accept an OTLP `ExportLogsServiceRequest`.
     */
    public function store(Request $request): JsonResponse
    {
        if ($this->isProtobuf($request)) {
            return new JsonResponse([
                'message' => 'Only the OTLP JSON encoding is supported. Set OTEL_EXPORTER_OTLP_PROTOCOL=http/json and send Content-Type: application/json.',
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $project = AuthenticateProjectApiKey::project($request);

        if ($project === null) {
            return new JsonResponse(['message' => 'API key invalid.'], Response::HTTP_UNAUTHORIZED);
        }

        $mapped = $this->mapper->map($this->decode($request), $project->id);

        if ($mapped->rows !== []) {
            try {
                $this->writer->write($mapped->rows);
            } catch (ClickHouseException $exception) {
                return $this->unavailable($exception);
            }
        }

        if (! $mapped->hasRejections()) {
            return new JsonResponse((object) [], Response::HTTP_OK);
        }

        return new JsonResponse([
            'partialSuccess' => [
                'rejectedLogRecords' => $mapped->rejected,
                'errorMessage' => $mapped->errorMessage ?? 'Some log records could not be parsed and were skipped.',
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Decode the request body, returning null when it is not valid JSON.
     */
    private function decode(Request $request): mixed
    {
        $decoded = json_decode($request->getContent(), true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
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
