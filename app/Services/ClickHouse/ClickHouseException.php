<?php

namespace App\Services\ClickHouse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class ClickHouseException extends RuntimeException
{
    /**
     * ClickHouse error codes that indicate the server is overloaded or is
     * temporarily unable to accept more work, rather than the statement
     * itself being wrong. Callers may translate these into a HTTP 503.
     *
     * @var array<int, int>
     */
    private const OVERLOAD_ERROR_CODES = [
        159, // TIMEOUT_EXCEEDED
        202, // TOO_MANY_SIMULTANEOUS_QUERIES
        203, // NO_FREE_CONNECTION
        209, // SOCKET_TIMEOUT
        210, // NETWORK_ERROR
        241, // MEMORY_LIMIT_EXCEEDED
        252, // TOO_MANY_PARTS
    ];

    /**
     * HTTP status codes that indicate the server is overloaded or unavailable.
     *
     * @var array<int, int>
     */
    private const OVERLOAD_STATUS_CODES = [429, 500, 502, 503, 504];

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?int $errorCode = null,
        private readonly bool $connectionFailed = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode ?? 0, $previous);
    }

    /**
     * Build an exception from a failed ClickHouse HTTP response.
     */
    public static function fromResponse(Response $response, string $statement): self
    {
        $errorCode = self::parseErrorCode($response);

        return new self(
            sprintf(
                'ClickHouse request failed with status %d: %s (statement: %s)',
                $response->status(),
                trim($response->body()),
                self::summarize($statement),
            ),
            statusCode: $response->status(),
            errorCode: $errorCode,
        );
    }

    /**
     * Build an exception from a transport level failure.
     */
    public static function fromConnectionException(ConnectionException $exception, string $statement): self
    {
        return new self(
            sprintf(
                'Could not reach ClickHouse: %s (statement: %s)',
                $exception->getMessage(),
                self::summarize($statement),
            ),
            connectionFailed: true,
            previous: $exception,
        );
    }

    /**
     * Build an exception for a malformed response body.
     */
    public static function fromInvalidResponse(string $message, ?Throwable $previous = null): self
    {
        return new self($message, previous: $previous);
    }

    /**
     * Determine whether the failure looks like ClickHouse being overloaded or
     * unavailable, meaning the request is worth retrying later.
     */
    public function isOverload(): bool
    {
        if ($this->connectionFailed) {
            return true;
        }

        if ($this->errorCode !== null && in_array($this->errorCode, self::OVERLOAD_ERROR_CODES, true)) {
            return true;
        }

        if ($this->errorCode !== null) {
            return false;
        }

        return $this->statusCode !== null && in_array($this->statusCode, self::OVERLOAD_STATUS_CODES, true);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function errorCode(): ?int
    {
        return $this->errorCode;
    }

    public function connectionFailed(): bool
    {
        return $this->connectionFailed;
    }

    /**
     * Pull the ClickHouse error code out of the response headers or body.
     */
    private static function parseErrorCode(Response $response): ?int
    {
        $header = $response->header('X-ClickHouse-Exception-Code');

        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        if (preg_match('/^Code:\s*(\d+)/', trim($response->body()), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Trim a statement so exception messages stay readable in logs.
     */
    private static function summarize(string $statement): string
    {
        $statement = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);

        return mb_strlen($statement) > 200
            ? mb_substr($statement, 0, 200).'...'
            : $statement;
    }
}
