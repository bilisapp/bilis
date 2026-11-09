<?php

namespace App\Notifications\Channels;

use App\Notifications\AlertNotification;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\Webhooks\SafeWebhookUrl;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use Illuminate\Http\Client\ConnectionException;

/**
 * A Slack incoming webhook: `POST {"text": …}` to the URL Slack gave, which
 * must be on hooks.slack.com. No bot, no OAuth.
 *
 * The URL is read from the channel at send time and checked by
 * {@see SafeWebhookUrl}; the request is pinned to the checked address. A
 * refused URL throws {@see UnsafeWebhookUrl}
 * (not retried); anything but a 2xx throws {@see DeliveryFailed} (retried).
 */
class SlackWebhookChannel
{
    public const HOST = 'hooks.slack.com';

    public function send(object $notifiable, AlertNotification $notification): void
    {
        $channel = $notification->notificationChannel() ?? throw DeliveryFailed::misconfigured('The channel no longer exists.');
        $target = SafeWebhookUrl::forEnvironment()->resolve((string) ($channel->config['url'] ?? ''), self::HOST);

        try {
            $response = $target->pendingRequest()->asJson()->post($target->url, [
                'text' => self::text($notification->message()),
            ]);
        } catch (ConnectionException $exception) {
            throw DeliveryFailed::unreachable($exception);
        }

        if (! $response->successful()) {
            throw DeliveryFailed::status($response->status());
        }
    }

    /**
     * The message in Slack mrkdwn: a bold title, the headline, the facts and
     * a link. User text is escaped so it cannot forge a link or a mention.
     */
    public static function text(AlertMessage $message): string
    {
        $lines = ['*'.self::escape($message->title).'*', self::escape($message->headline)];

        foreach ($message->facts as $label => $value) {
            $lines[] = '• '.$label.': '.self::escape($value);
        }

        if ($message->url !== null) {
            $lines[] = '<'.$message->url.'|Open in Bilis>';
        }

        if ($message->footer !== null) {
            $lines[] = self::escape($message->footer);
        }

        return implode("\n", $lines);
    }

    /**
     * Slack's three control characters.
     */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
