<?php

namespace App\Services\Autofix;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A failure while talking to the GitHub App API, or while building the App
 * JWT that authenticates those calls.
 */
class GitHubAppException extends RuntimeException
{
    /**
     * HTTP status codes worth retrying rather than failing the job on.
     *
     * @var array<int, int>
     */
    private const TRANSIENT_STATUS_CODES = [429, 500, 502, 503, 504];

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
        return new self(sprintf('The autofix GitHub App is not configured: %s is empty.', $key));
    }

    /**
     * Build an exception for a private key that cannot be used for signing.
     */
    public static function invalidPrivateKey(string $reason): self
    {
        return new self(sprintf('The autofix GitHub App private key is unusable: %s', $reason));
    }

    /**
     * Build an exception from a failed installation token exchange.
     */
    public static function fromResponse(Response $response, int $installationId): self
    {
        return new self(
            sprintf(
                'GitHub refused an installation token for installation %d with status %d: %s',
                $installationId,
                $response->status(),
                trim(mb_substr($response->body(), 0, 500)),
            ),
            statusCode: $response->status(),
        );
    }

    /**
     * Build an exception from a transport level failure.
     */
    public static function fromConnectionException(ConnectionException $exception, int $installationId): self
    {
        return new self(
            sprintf('Could not reach GitHub for installation %d: %s', $installationId, $exception->getMessage()),
            connectionFailed: true,
            previous: $exception,
        );
    }

    /**
     * Build an exception for a token response that is not shaped as expected.
     */
    public static function fromInvalidResponse(int $installationId): self
    {
        return new self(
            sprintf('GitHub returned no token for installation %d.', $installationId),
        );
    }

    /**
     * Determine whether the failure is worth retrying later.
     */
    public function isTransient(): bool
    {
        if ($this->connectionFailed) {
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
