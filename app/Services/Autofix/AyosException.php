<?php

namespace App\Services\Autofix;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A failure while talking to Ayos over the control plane.
 *
 * Three kinds live here, and the dispatcher treats them differently:
 * backpressure (`429`, Ayos is full — the job stays pending and is retried),
 * transient failures (a connection failure or a 5xx — retried a bounded number
 * of times), and everything else, which fails the job outright.
 */
class AyosException extends RuntimeException
{
    /**
     * HTTP status codes worth retrying rather than failing the job on.
     *
     * @var array<int, int>
     */
    private const TRANSIENT_STATUS_CODES = [408, 500, 502, 503, 504];

    /**
     * The status Ayos answers with when it is already at capacity.
     */
    public const BACKPRESSURE_STATUS = 429;

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly bool $connectionFailed = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /**
     * Build an exception for configuration that has not been filled in.
     */
    public static function missingConfiguration(string $key): self
    {
        return new self(sprintf('Ayos is not configured: %s is empty.', $key));
    }

    /**
     * Build an exception from a non-2xx response.
     */
    public static function fromResponse(Response $response, string $path): self
    {
        return new self(
            sprintf(
                'Ayos answered %s with status %d: %s',
                $path,
                $response->status(),
                trim(mb_substr($response->body(), 0, 500)),
            ),
            statusCode: $response->status(),
        );
    }

    /**
     * Build an exception from a transport level failure.
     */
    public static function fromConnectionException(ConnectionException $exception, string $path): self
    {
        return new self(
            sprintf('Could not reach Ayos for %s: %s', $path, $exception->getMessage()),
            connectionFailed: true,
            previous: $exception,
        );
    }

    /**
     * Determine whether Ayos refused the job because it is already full.
     *
     * This is not a failure: Ayos never holds a backlog, so the control plane
     * keeps the job queued and offers it again later.
     */
    public function isBackpressure(): bool
    {
        return $this->statusCode === self::BACKPRESSURE_STATUS;
    }

    /**
     * Determine whether the failure is worth retrying later.
     */
    public function isTransient(): bool
    {
        if ($this->connectionFailed || $this->isBackpressure()) {
            return true;
        }

        return $this->statusCode !== null && in_array($this->statusCode, self::TRANSIENT_STATUS_CODES, true);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function connectionFailed(): bool
    {
        return $this->connectionFailed;
    }
}
