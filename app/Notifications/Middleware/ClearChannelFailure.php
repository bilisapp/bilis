<?php

namespace App\Notifications\Middleware;

use App\Models\NotificationChannel;
use Closure;

/**
 * Queue middleware for one channel's delivery: once a send goes through, the
 * channel's last failure is cleared, so the settings page stops warning about
 * a channel that works again.
 */
final readonly class ClearChannelFailure
{
    public function __construct(public int $channelId) {}

    public function handle(mixed $job, Closure $next): mixed
    {
        $result = $next($job);

        self::clear($this->channelId);

        return $result;
    }

    /**
     * Clear the channel's failure, writing only when there is one.
     */
    public static function clear(int $channelId): void
    {
        NotificationChannel::query()
            ->whereKey($channelId)
            ->whereNotNull('last_failed_at')
            ->update(['last_failed_at' => null, 'last_error' => null]);
    }
}
