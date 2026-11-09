<?php

namespace App\Notifications\Middleware;

use App\Models\NotificationChannel;
use Closure;
use Illuminate\Notifications\SendQueuedNotifications;

/**
 * Queue middleware for one channel's delivery: once a send goes through, the
 * channel's last failure is cleared, so the settings page stops warning about
 * a channel that works again.
 *
 * A send that `shouldSend()` skipped — the channel was switched off or the
 * event pruned while the job waited — delivered nothing, so it clears
 * nothing either.
 */
final readonly class ClearChannelFailure
{
    public function __construct(public int $channelId) {}

    public function handle(mixed $job, Closure $next): mixed
    {
        $willSend = self::willSend($job);
        $result = $next($job);

        if ($willSend) {
            self::clear($this->channelId);
        }

        return $result;
    }

    /**
     * Whether the queued notification is about to be delivered, asked before
     * the send (afterwards a pruned event would read as skipped).
     */
    private static function willSend(mixed $job): bool
    {
        if (! $job instanceof SendQueuedNotifications || ! method_exists($job->notification, 'shouldSend')) {
            return true;
        }

        foreach ($job->notifiables as $notifiable) {
            foreach ($job->channels as $channel) {
                if ($job->notification->shouldSend($notifiable, $channel) !== false) {
                    return true;
                }
            }
        }

        return false;
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
