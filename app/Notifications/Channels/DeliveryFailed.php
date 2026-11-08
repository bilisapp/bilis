<?php

namespace App\Notifications\Channels;

use App\Notifications\AlertNotification;
use App\Notifications\Middleware\FailWithoutRetry;
use RuntimeException;
use Throwable;

/**
 * A notification the receiving end did not accept. The message is one short
 * sentence for the channel's settings page — it never carries the URL (a
 * Slack URL is a credential) or a response body.
 *
 * A transient failure is thrown so the queue retries; a permanent one (the
 * channel's own configuration is unusable) fails the job at once, see
 * {@see FailWithoutRetry}. Either way
 * {@see AlertNotification::failed()} records it on the channel.
 */
final class DeliveryFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function status(int $status): self
    {
        return new self("The endpoint answered HTTP {$status}.");
    }

    public static function unreachable(?Throwable $previous = null): self
    {
        return new self('The endpoint could not be reached (connection failed or timed out).', previous: $previous);
    }

    /**
     * The channel's configuration cannot work; waiting will not change that.
     */
    public static function misconfigured(string $message, ?Throwable $previous = null): self
    {
        return new self($message, true, $previous);
    }
}
