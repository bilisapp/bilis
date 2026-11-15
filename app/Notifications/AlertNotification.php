<?php

namespace App\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\AlertEvent;
use App\Models\NotificationChannel;
use App\Notifications\Channels\DeliveryFailed;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Middleware\ClearChannelFailure;
use App\Notifications\Middleware\FailWithoutRetry;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\AlertNotifier;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * One alert event, delivered to one notification channel
 * (specs/alerting.md, "Notifications").
 *
 * Sent on demand through `Notification::route()` by
 * {@see AlertNotifier}, one per channel, so a failing
 * channel retries alone and never holds another back. It carries ids, not
 * models: the event and channel are read fresh at send time, and a pruned
 * event or a deleted or disabled channel turns the send into a no-op.
 *
 * Retries are the queue's: ten tries, backing off from 30 s to an hour. An
 * {@see UnsafeWebhookUrl} or a misconfigured channel fails at once
 * ({@see FailWithoutRetry}) — it will not heal by waiting. Once the queue
 * gives up, {@see failed()} records the reason on the channel.
 */
class AlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /**
     * @param  int|null  $eventId  null only for a test message
     */
    public function __construct(
        public readonly int $channelId,
        public readonly NotificationChannelType $channelType,
        public readonly ?int $eventId,
    ) {
        $this->onQueue('alerts');
        $this->afterCommit();
    }

    /**
     * A test notification for the channel: no event behind it.
     */
    public static function test(NotificationChannel $channel): self
    {
        return new self($channel->id, $channel->type, null);
    }

    /**
     * @return list<int> seconds before each retry: 30 s, doubling, capped at an hour
     */
    public function backoff(): array
    {
        return array_map(fn (int $attempt): int => min(30 << $attempt, 3600), range(0, $this->tries - 2));
    }

    /**
     * The single channel this notification was created for.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [match ($this->channelType) {
            NotificationChannelType::Email => 'mail',
            NotificationChannelType::Slack => SlackWebhookChannel::class,
            NotificationChannelType::Webhook => WebhookChannel::class,
        }];
    }

    /**
     * Skip the send when the event was pruned or the channel was deleted or
     * switched off while the notification waited in the queue.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $notificationChannel = $this->notificationChannel();

        if ($notificationChannel === null || ! $notificationChannel->enabled) {
            return false;
        }

        return $this->eventId === null || AlertEvent::query()->whereKey($this->eventId)->exists();
    }

    /**
     * @return list<object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        return [
            new FailWithoutRetry,
            new ClearChannelFailure($this->channelId),
        ];
    }

    /**
     * The event as an email (`resources/views/mail/alert.blade.php`): its
     * state and severity, the rule and series, the sentence, the facts, what
     * the window caught, and where to look.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message();
        $isIssue = in_array($message->state, ['new', 'regressed'], true);
        /** @var array<string, string> $labels */
        $labels = (array) ($message->payload['labels'] ?? []);
        $labelText = $labels !== [] ? AlertMessage::labelText($labels) : null;

        // An issue is named by its exception, its service printed under it; a
        // rule by its name, its series' labels under it. Neither is repeated
        // as a fact, and the severity has the state line.
        $heading = $isIssue && isset($message->facts['Issue'])
            ? $message->facts['Issue']
            : self::heading($message->title, $labelText);
        $subtitle = $isIssue ? ($message->facts['Service'] ?? null) : $labelText;
        $hidden = $isIssue ? ['Issue', 'Service'] : ['Severity', 'Labels'];

        return (new MailMessage)
            ->subject($message->title)
            ->markdown('mail.alert', [
                'alert' => $message,
                'heading' => $heading,
                'labels' => $subtitle,
                'facts' => array_diff_key($message->facts, array_flip($hidden)),
                'actionText' => $isIssue ? 'Open the issue' : 'Open in Bilis',
                'ruleUrl' => is_string($message->payload['links']['rule'] ?? null) ? $message->payload['links']['rule'] : null,
                'editUrl' => is_string($message->payload['links']['edit'] ?? null) ? $message->payload['links']['edit'] : null,
                'footnote' => 'You get this because a Bilis notification channel lists you.',
            ]);
    }

    /**
     * The title without its `[FIRING]` tag (the state line says it) and
     * without the labels (printed under it in mono).
     */
    private static function heading(string $title, ?string $labels): string
    {
        $heading = (string) preg_replace('/^\[[^\]]+\]\s*/', '', $title);

        if ($labels !== null && str_ends_with($heading, ' — '.$labels)) {
            $heading = substr($heading, 0, -strlen(' — '.$labels));
        }

        return $heading;
    }

    /**
     * What this notification says, shaped once for every channel.
     */
    public function message(): AlertMessage
    {
        if ($this->eventId === null) {
            $channel = $this->notificationChannel() ?? throw DeliveryFailed::misconfigured('The channel no longer exists.');

            return AlertMessage::test($channel);
        }

        return AlertMessage::fromEvent(AlertEvent::query()->with(['rule', 'team'])->findOrFail($this->eventId));
    }

    /**
     * The channel this notification is for, read fresh.
     */
    public function notificationChannel(): ?NotificationChannel
    {
        return NotificationChannel::query()->find($this->channelId);
    }

    /**
     * The queue gave up: record why on the channel, in words safe to show.
     */
    public function failed(Throwable $exception): void
    {
        NotificationChannel::query()->whereKey($this->channelId)->update([
            'last_failed_at' => now(),
            'last_error' => self::describe($exception),
        ]);
    }

    /**
     * A short, human reason that never repeats a URL or a secret: only our
     * own exceptions' messages are written for the user.
     */
    public static function describe(Throwable $exception): string
    {
        if ($exception instanceof UnsafeWebhookUrl || $exception instanceof DeliveryFailed) {
            return Str::limit($exception->getMessage(), 250);
        }

        if ($exception instanceof TransportExceptionInterface) {
            return 'The email could not be handed to the mail server.';
        }

        return 'The notification could not be delivered.';
    }
}
