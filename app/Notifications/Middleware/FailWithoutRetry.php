<?php

namespace App\Notifications\Middleware;

use App\Notifications\Channels\DeliveryFailed;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use Closure;
use Throwable;

/**
 * Queue middleware that fails a delivery at once — no retry — when the error
 * cannot heal by waiting: a webhook URL the SSRF guard refuses, or a channel
 * whose own configuration is unusable.
 *
 * Laravel's `FailOnException` does the same, but holds a closure, and a
 * queued notification's middleware is serialized with the job.
 */
final class FailWithoutRetry
{
    /**
     * @param  object  $job  the queued `SendQueuedNotifications` command
     */
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $exception) {
            if (self::isPermanent($exception) && method_exists($job, 'fail')) {
                $job->fail($exception);
            }

            throw $exception;
        }
    }

    public static function isPermanent(Throwable $exception): bool
    {
        return $exception instanceof UnsafeWebhookUrl
            || ($exception instanceof DeliveryFailed && $exception->permanent);
    }
}
