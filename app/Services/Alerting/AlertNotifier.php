<?php

namespace App\Services\Alerting;

use App\Enums\NotificationChannelType;
use App\Models\AlertEvent;
use App\Models\NotificationChannel;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\DeliveryFailed;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Middleware\ClearChannelFailure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * The one seam between evaluation and delivery.
 *
 * `EvaluateAlertRule` calls {@see notify()} after commit, once per newly
 * recorded event, and never for a muted rule. Each enabled channel gets its
 * own queued {@see AlertNotification}, sent on demand through
 * `Notification::route()`, so one failing channel never blocks another:
 *
 * - a rule's event → the rule's enabled channels;
 * - an event without a rule (issue_new / issue_regressed) → the team's
 *   enabled channels flagged `issue_events`.
 */
class AlertNotifier
{
    public const NO_RECIPIENTS = 'The channel has no email recipients.';

    /**
     * Queue the event for each of its channels.
     */
    public function notify(AlertEvent $event): void
    {
        foreach ($this->channelsFor($event) as $channel) {
            $notifiable = $this->notifiable($channel);

            if ($notifiable === null) {
                $this->recordFailure($channel, self::NO_RECIPIENTS);

                continue;
            }

            $notifiable->notify(new AlertNotification($channel->id, $channel->type, $event->id));
        }
    }

    /**
     * Send a clearly-labelled test message through the channel now, on this
     * request, and let any failure surface to the caller. A success clears
     * the channel's recorded failure.
     *
     * @throws \Throwable
     */
    public function sendTest(NotificationChannel $channel): void
    {
        $notifiable = $this->notifiable($channel)
            ?? throw new DeliveryFailed(self::NO_RECIPIENTS);

        $notifiable->notifyNow(AlertNotification::test($channel));

        ClearChannelFailure::clear($channel->id);
    }

    /**
     * Nothing could be queued for the channel: say so on the settings page,
     * the same way a failed delivery is recorded.
     */
    private function recordFailure(NotificationChannel $channel, string $reason): void
    {
        NotificationChannel::query()->whereKey($channel->id)->update([
            'last_failed_at' => now(),
            'last_error' => $reason,
        ]);
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    private function channelsFor(AlertEvent $event): Collection
    {
        if ($event->alert_rule_id !== null) {
            return NotificationChannel::query()
                ->where('team_id', $event->team_id)
                ->where('enabled', true)
                ->whereHas('alertRules', fn ($query) => $query->whereKey($event->alert_rule_id))
                ->orderBy('id')
                ->get();
        }

        return NotificationChannel::query()
            ->where('team_id', $event->team_id)
            ->where('enabled', true)
            ->where('issue_events', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Where the channel delivers to, as an on-demand notifiable. Mail is
     * routed to its addresses; Slack and webhook channels read their URL
     * (a credential) from the encrypted config at send time, so only the
     * channel id travels through the queue.
     */
    private function notifiable(NotificationChannel $channel): ?AnonymousNotifiable
    {
        return match ($channel->type) {
            NotificationChannelType::Email => ($addresses = $this->emailAddresses($channel)) === []
                ? null
                : Notification::route('mail', $addresses),
            NotificationChannelType::Slack => Notification::route(SlackWebhookChannel::class, $channel->id),
            NotificationChannelType::Webhook => Notification::route(WebhookChannel::class, $channel->id),
        };
    }

    /**
     * `{"recipients": "members"}` → every team member's email;
     * `{"addresses": [...]}` → those addresses.
     *
     * @return list<string>
     */
    private function emailAddresses(NotificationChannel $channel): array
    {
        $config = $channel->config;

        if (($config['recipients'] ?? null) === 'members') {
            $emails = $channel->team()->firstOrFail()->members()->pluck('email')
                ->map(fn (mixed $email): string => (string) $email);
        } else {
            $emails = collect(is_array($config['addresses'] ?? null) ? $config['addresses'] : [])
                ->map(fn (mixed $email): string => is_string($email) ? trim($email) : '');
        }

        return array_values($emails->filter(fn (string $email): bool => $email !== '')->unique()->all());
    }
}
