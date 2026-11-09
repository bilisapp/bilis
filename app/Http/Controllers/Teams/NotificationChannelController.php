<?php

namespace App\Http\Controllers\Teams;

use App\Enums\NotificationChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveNotificationChannelRequest;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Notifications\AlertNotification;
use App\Services\Alerting\AlertNotifier;
use App\Services\Alerting\NotificationChannelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

/**
 * Where a team's alerts are delivered: email, Slack, or a signed webhook
 * (specs/alerting.md, "Notifications").
 *
 * Kept apart from the general team update for the same reason as the model
 * API keys: a channel's config is a credential. It is written here only,
 * returned to the browser only through {@see NotificationChannel::toSummary()}
 * (a URL as its host, a secret not at all), and changed only by owners and
 * admins. `{notificationChannel}` is bound through the team in the route, so
 * another team's channel is a 404.
 */
class NotificationChannelController extends Controller
{
    /**
     * Add a channel to the team.
     *
     * A webhook without a secret gets a generated one, shown exactly once in
     * the flash so the receiver can be configured with it.
     */
    public function store(SaveNotificationChannelRequest $request, Team $team): RedirectResponse
    {
        $type = $request->type();
        $config = $request->config();
        $generated = $type === NotificationChannelType::Webhook && ! isset($config['secret']);
        $config = NotificationChannelConfig::prepare($type, $config);

        $channel = $team->notificationChannels()->create([
            'type' => $type,
            'name' => (string) $request->validated('name'),
            'config' => $config,
            'enabled' => (bool) $request->validated('enabled', true),
            'issue_events' => (bool) $request->validated('issue_events', false),
        ]);

        if ($generated) {
            Inertia::flash('channelSecret', [
                'channelId' => $channel->id,
                'name' => $channel->name,
                'secret' => (string) $config['secret'],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel added.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Change a channel's name, target or flags. Its type is fixed.
     */
    public function update(SaveNotificationChannelRequest $request, Team $team, NotificationChannel $notificationChannel): RedirectResponse
    {
        $notificationChannel->fill([
            'name' => (string) $request->validated('name'),
            'config' => NotificationChannelConfig::prepare($notificationChannel->type, $request->config()),
            'enabled' => (bool) $request->validated('enabled', $notificationChannel->enabled),
            'issue_events' => (bool) $request->validated('issue_events', $notificationChannel->issue_events),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel saved.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch a channel on or off. A disabled channel records nothing and
     * sends nothing; rules keep pointing at it.
     *
     * The card sends the state it wants (`enabled`), so a double click or a
     * repeated request lands on the same state instead of flipping back;
     * without it the channel is flipped.
     */
    public function toggle(Request $request, Team $team, NotificationChannel $notificationChannel): RedirectResponse
    {
        Gate::authorize('update', $team);

        $validated = $request->validate(['enabled' => ['sometimes', 'boolean']]);

        $notificationChannel->forceFill([
            'enabled' => array_key_exists('enabled', $validated) ? (bool) $validated['enabled'] : ! $notificationChannel->enabled,
        ])->save();

        return back();
    }

    /**
     * Remove a channel. Rules that pointed at it simply stop sending there.
     */
    public function destroy(Team $team, NotificationChannel $notificationChannel): RedirectResponse
    {
        Gate::authorize('update', $team);

        $notificationChannel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification channel removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Send a clearly-labelled test through the channel now, and say how it
     * went. The failure text is {@see AlertNotification::describe()}'s: our
     * own messages verbatim, anything else a generic sentence — never a URL,
     * a secret or a stack.
     */
    public function test(Team $team, NotificationChannel $notificationChannel, AlertNotifier $notifier): JsonResponse
    {
        Gate::authorize('update', $team);

        // A disabled channel's notifications are skipped by design, so a
        // "sent" here would be a lie.
        if (! $notificationChannel->enabled) {
            return response()->json(['ok' => false, 'message' => 'The channel is switched off. Switch it on to send a test.']);
        }

        try {
            $notifier->sendTest($notificationChannel);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'message' => AlertNotification::describe($exception)]);
        }

        return response()->json(['ok' => true, 'message' => 'Test notification sent.']);
    }
}
