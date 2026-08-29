<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProjectApiKey;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\Envelope\Envelope;
use App\Services\Ingest\Envelope\ErrorEventMapper;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\MappedLogs;
use App\Services\Ingest\RequestBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ingest for error reporting clients that batch their events into envelopes.
 *
 * Such a client derives its endpoint from the DSN it is configured with and
 * cannot be told to use another shape, which is why these paths sit at
 * `/api/{id}/envelope` rather than under `/api/v1`. The project id in the path
 * is the DSN's, not ours: the project is always the one the public key
 * authenticated to.
 *
 * Only `event` items are stored. Sessions, transactions, attachments and
 * client reports belong to products Bilis does not have; they are counted as
 * skipped so a client sending them is never given an error it cannot act on.
 */
class EnvelopeIngestController extends Controller
{
    /**
     * The number of seconds clients should wait before retrying a rejection.
     */
    private const RETRY_AFTER_SECONDS = 5;

    /**
     * The envelope item types that become log records.
     */
    private const STORED_ITEM_TYPE = 'event';

    public function __construct(
        private readonly ErrorEventMapper $mapper,
        private readonly LogWriter $writer,
    ) {}

    /**
     * Accept an envelope of events.
     */
    public function envelope(Request $request): JsonResponse
    {
        return $this->accept($request, function (?string $body) use ($request): MappedLogs {
            $envelope = Envelope::parse($body);

            if ($envelope->malformed && $envelope->items === []) {
                return new MappedLogs(rejected: 1, errorMessage: 'Request body could not be read as an envelope.');
            }

            $events = [];
            $skipped = 0;

            foreach ($envelope->items as $item) {
                if ($item->type !== self::STORED_ITEM_TYPE) {
                    $skipped++;

                    continue;
                }

                $event = $item->json();

                if ($event === null) {
                    $skipped++;

                    continue;
                }

                $events[] = $event;
            }

            $mapped = $this->mapper->map($events, $this->projectId($request));

            return new MappedLogs($mapped->rows, $mapped->rejected + $skipped);
        });
    }

    /**
     * Answer a browser's preflight.
     *
     * The response body is empty and the decision is not made here: whether
     * the browser is told it may post is entirely the CORS middleware's
     * answer, which it writes onto this response on the way out.
     */
    public function preflight(): Response
    {
        return response()->noContent();
    }

    /**
     * Accept a bare event from a client still using the older endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->accept($request, function (?string $body) use ($request): MappedLogs {
            $event = $body === null ? null : json_decode($body, true);

            if (! is_array($event) || array_is_list($event)) {
                return new MappedLogs(rejected: 1, errorMessage: 'Request body could not be read as an event.');
            }

            return $this->mapper->map([$event], $this->projectId($request));
        });
    }

    /**
     * Read the body, map it with the given reader, and write what came out.
     *
     * @param  callable(?string): MappedLogs  $read
     */
    private function accept(Request $request, callable $read): JsonResponse
    {
        $encoding = RequestBody::encoding($request);

        if (! RequestBody::isSupportedEncoding($encoding)) {
            return new JsonResponse([
                'message' => "Content-Encoding {$encoding} is not supported. Send the body uncompressed or with gzip or deflate.",
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if (AuthenticateProjectApiKey::project($request) === null) {
            return new JsonResponse(['message' => 'Public key invalid.'], Response::HTTP_UNAUTHORIZED);
        }

        $mapped = $read(RequestBody::read($request));

        if ($mapped->rows !== []) {
            try {
                $this->writer->write($mapped->rows);
            } catch (ClickHouseException $exception) {
                return $this->unavailable($exception);
            }
        }

        /*
         * The client reads the id and nothing else, and treats any 2xx as
         * delivered. Nothing here is ever a 4xx: a payload Bilis cannot map is
         * dropped and counted, never blamed on the client (ingest.md).
         */
        return new JsonResponse(['id' => Str::remove('-', (string) Str::uuid())], Response::HTTP_OK);
    }

    /**
     * The project the authenticated public key belongs to.
     */
    private function projectId(Request $request): string
    {
        return (string) AuthenticateProjectApiKey::project($request)?->id;
    }

    /**
     * Translate a ClickHouse failure into a retryable 503.
     */
    private function unavailable(ClickHouseException $exception): JsonResponse
    {
        Log::error('Failed to write error events to ClickHouse.', [
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
