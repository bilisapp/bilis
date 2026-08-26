<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\SimpleLogMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The simple JSON ingest endpoint, for clients without an OTLP exporter.
 *
 * The payload is a single log object or a list of them. Records that cannot be
 * mapped are skipped and reported in the response counts, never rejected with
 * a client error.
 */
class LogIngestController extends Controller
{
    /**
     * The number of seconds clients should wait before retrying a rejection.
     */
    private const RETRY_AFTER_SECONDS = 5;

    public function __construct(
        private readonly SimpleLogMapper $mapper,
        private readonly LogWriter $writer,
    ) {}

    /**
     * Accept one or many simple log records.
     */
    public function store(Request $request): JsonResponse
    {
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

        $payload = [
            'accepted' => $mapped->accepted(),
            'skipped' => $mapped->rejected,
        ];

        if ($mapped->errorMessage !== null) {
            $payload['message'] = $mapped->errorMessage;
        }

        return new JsonResponse($payload, Response::HTTP_ACCEPTED);
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
     * Translate a ClickHouse failure into a retryable 503.
     */
    private function unavailable(ClickHouseException $exception): JsonResponse
    {
        Log::error('Failed to write log records to ClickHouse.', [
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
