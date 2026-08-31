<?php

namespace App\Services\Ingest;

use App\Services\Ingest\Protobuf\OtlpProtobufEncoder;
use Illuminate\Http\Response;

/**
 * Builds the success and partial-success responses for both OTLP endpoints, in
 * whichever encoding the export arrived in.
 *
 * OTLP/HTTP is symmetric: a protobuf request is answered with a protobuf
 * response, a JSON request with JSON. Answering a protobuf export with JSON
 * still stores the data and still returns 200, so nothing here notices — but
 * the client fails to parse the body and logs an error per export. That is the
 * loudest possible way to look broken while working, which is the opposite of
 * what the never-blame-the-client contract is for.
 *
 * Error responses (415, 401, 503) stay JSON on purpose. They carry a
 * human-readable `message` that has no field in the OTLP response schema, and a
 * client that is being told its content type is unsupported has already shown
 * it cannot be answered in that content type.
 */
class OtlpResponse
{
    /**
     * The content type a protobuf export is answered with.
     */
    public const PROTOBUF_CONTENT_TYPE = 'application/x-protobuf';

    public function __construct(private readonly OtlpProtobufEncoder $encoder) {}

    /**
     * Everything in the export was stored.
     */
    public function success(bool $protobuf): Response
    {
        if ($protobuf) {
            return $this->protobuf($this->encoder->success());
        }

        return $this->json((object) []);
    }

    /**
     * Some records were skipped and counted.
     *
     * `$rejectedField` is the JSON name of the count, which is the one thing
     * that differs between the two signals: `rejectedSpans` for traces,
     * `rejectedLogRecords` for logs. Both are field 1 on the wire, so the
     * protobuf branch does not need to know which caller it is serving.
     */
    public function partialSuccess(bool $protobuf, string $rejectedField, int $rejected, string $errorMessage): Response
    {
        if ($protobuf) {
            return $this->protobuf($this->encoder->partialSuccess($rejected, $errorMessage));
        }

        return $this->json([
            'partialSuccess' => [
                $rejectedField => $rejected,
                'errorMessage' => $errorMessage,
            ],
        ]);
    }

    /**
     * A protobuf body, which for a plain success is legitimately zero bytes.
     *
     * Illuminate's Response rather than Symfony's: the framework and the test
     * client both write an `exception` property onto whatever a controller
     * returns, and only Illuminate's declares one.
     */
    private function protobuf(string $body): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => self::PROTOBUF_CONTENT_TYPE,
        ]);
    }

    /**
     * A JSON body, built through the same Response class so a caller's return
     * type does not change with the encoding.
     */
    private function json(mixed $payload): Response
    {
        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
