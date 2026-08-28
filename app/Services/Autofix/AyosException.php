<?php

namespace App\Services\Autofix;

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A failure while starting or stopping an Ayos run.
 *
 * Three kinds live here, and the dispatcher treats them differently:
 * backpressure (`429` — the platform is at its concurrency limit, so the job
 * stays pending and is offered again later), transient failures (a connection
 * failure or a 5xx — retried a bounded number of times), and everything else,
 * which fails the job outright.
 *
 * Note that backpressure is now the platform's, not Ayos's: a runner with no
 * inbound surface cannot refuse anything. The handling is unchanged, because
 * "keep it queued and try again" was always the right answer to a full box.
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
     * Build an exception for a team with no model credential.
     *
     * Named rather than generic: "autofix.llm.api_key is empty" sends an
     * operator to a config file, when what is actually missing is a key the
     * CUSTOMER has to paste into their own team settings.
     */
    public static function missingLlmKey(Team $team): self
    {
        return new self(sprintf(
            'The team "%s" has no model API key configured. Add one in team settings before running a fix job.',
            $team->name,
        ));
    }

    /**
     * Build an exception for a runner that could not be started at all.
     *
     * Transient by default: a machine that could not fork this second may well
     * fork the next, and failing the job outright would turn a hiccup into a
     * dead job.
     */
    public static function runnerUnavailable(string $reason): self
    {
        return new self(
            sprintf('The Ayos run could not be started: %s.', $reason),
            connectionFailed: true,
        );
    }

    /**
     * Build an exception from a transport level failure.
     */
    public static function fromConnectionException(ConnectionException $exception, string $path): self
    {
        return new self(
            sprintf('Could not reach the run platform for %s: %s', $path, $exception->getMessage()),
            connectionFailed: true,
            previous: $exception,
        );
    }

    /**
     * Determine whether the job was refused because the platform is full.
     *
     * This is not a failure: nothing holds a backlog for us, so the control
     * plane keeps the job queued and offers it again later.
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
