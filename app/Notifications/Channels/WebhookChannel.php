<?php

namespace App\Notifications\Channels;

use App\Notifications\AlertNotification;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\Webhooks\SafeWebhookUrl;
use App\Services\Alerting\Webhooks\StandardWebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A signed generic webhook (Standard Webhooks, standardwebhooks.com).
 *
 * Body: `{type, timestamp, data}` where `data` is {@see AlertMessage::$payload}.
 * Headers: `webhook-id`, `webhook-timestamp`, `webhook-signature` from
 * {@see StandardWebhookSigner}. The id is `msg_{event id}`, the same on every
 * retry, so a receiver can de-duplicate; the signing timestamp is the send
 * time, so retries stay inside the receiver's tolerance.
 *
 * Same SSRF guard and failure contract as {@see SlackWebhookChannel}.
 */
class WebhookChannel
{
    public function send(object $notifiable, AlertNotification $notification): void
    {
        $channel = $notification->notificationChannel() ?? throw DeliveryFailed::misconfigured('The channel no longer exists.');
        $target = SafeWebhookUrl::forEnvironment()->resolve((string) ($channel->config['url'] ?? ''));

        try {
            $signer = new StandardWebhookSigner((string) ($channel->config['secret'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw DeliveryFailed::misconfigured('The channel\'s signing secret is not a valid whsec_ secret.', $exception);
        }

        $message = $notification->message();
        $body = self::body($message);
        $msgId = $notification->eventId !== null ? 'msg_'.$notification->eventId : 'msg_test_'.Str::ulid();

        try {
            $response = $target->pendingRequest()
                ->withHeaders($signer->headers($msgId, now()->getTimestamp(), $body))
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (ConnectionException $exception) {
            throw DeliveryFailed::unreachable($exception);
        }

        if (! $response->successful()) {
            throw DeliveryFailed::status($response->status());
        }
    }

    /**
     * The exact bytes that are signed and sent.
     */
    public static function body(AlertMessage $message): string
    {
        return json_encode([
            'type' => $message->type,
            'timestamp' => $message->occurredAt->utc()->toIso8601ZuluString(),
            'data' => $message->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
