<?php

namespace App\Services\Autofix;

use RuntimeException;

/**
 * Raised when a browser stream token cannot be minted.
 *
 * Always an operator problem — a missing or malformed Ed25519 signing key —
 * never something the viewer did, so it is reported as a 503 rather than as a
 * client error.
 */
class StreamTokenException extends RuntimeException
{
    /**
     * The signing key is absent from configuration.
     */
    public static function missingKey(): self
    {
        return new self('The autofix stream signing key (autofix.stream_jwt.private_key) is not configured.');
    }

    /**
     * The configured signing key is not a usable Ed25519 key.
     */
    public static function invalidKey(string $reason): self
    {
        return new self('The autofix stream signing key is unusable: '.$reason.'.');
    }

    /**
     * The browser-facing Ayos origin is absent from configuration.
     */
    public static function missingStreamUrl(): self
    {
        return new self('The Ayos stream URL (autofix.ayos.stream_url) is not configured.');
    }
}
