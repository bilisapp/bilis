<?php

use App\Enums\NotificationChannelType;
use App\Enums\TeamRole;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AlertNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Notification channels on the team settings page. Their config is a
 * credential: a Slack or webhook URL leaves the server as its host, a signing
 * secret only once — in the flash after it is generated.
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->otherTeam = Team::factory()->create(['slug' => 'globex']);
});

const SLACK_URL = 'https://hooks.slack.com/services/T0000/B0000/abcdefghijklmnopqrstuvwx';

it('lists channels on the team page with URLs masked and secrets withheld', function () {
    NotificationChannel::factory()->for($this->team)->slack()->create(['name' => '#alerts', 'config' => ['url' => SLACK_URL]]);
    NotificationChannel::factory()->for($this->team)->webhook()->failing()->create([
        'name' => 'Incident bridge',
        'config' => ['url' => 'https://ops.acme.dev/hooks/bilis?token=sekret', 'secret' => 'whsec_'.base64_encode(str_repeat('k', 24))],
    ]);
    NotificationChannel::factory()->for($this->team)->create(['name' => 'On-call inbox', 'config' => ['addresses' => ['oncall@acme.dev']]]);
    NotificationChannel::factory()->for($this->otherTeam)->create(['name' => 'Not ours']);

    $response = $this->actingAs($this->member)
        ->get(route('teams.edit', ['team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('notificationChannels', 3)
            ->where('notificationChannels.0.name', '#alerts')
            ->where('notificationChannels.0.target', 'hooks.slack.com (URL hidden)')
            ->where('notificationChannels.0.host', 'hooks.slack.com')
            ->where('notificationChannels.1.name', 'Incident bridge')
            ->where('notificationChannels.1.hasSecret', true)
            ->where('notificationChannels.1.lastError', 'HTTP 410 Gone')
            ->where('notificationChannels.2.addresses', ['oncall@acme.dev'])
            ->missing('notificationChannels.0.config')
        );

    expect($response->getContent())
        ->not->toContain('/services/T0000')
        ->not->toContain('token=sekret')
        ->not->toContain('whsec_');
});

it('adds an email channel to every member, or to listed addresses', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'email', 'name' => 'Everyone', 'config' => ['recipients' => 'members'],
        ])
        ->assertRedirect(route('teams.edit', ['team' => 'acme']));

    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'email', 'name' => 'On-call', 'issue_events' => true,
            'config' => ['addresses' => "oncall@acme.dev\nsre@acme.dev, "],
        ])
        ->assertSessionHasNoErrors();

    [$everyone, $oncall] = NotificationChannel::query()->orderBy('id')->get()->all();

    expect($everyone->config)->toBe(['recipients' => 'members'])
        ->and($oncall->config)->toBe(['addresses' => ['oncall@acme.dev', 'sre@acme.dev']])
        ->and($oncall->issue_events)->toBeTrue()
        ->and($oncall->team_id)->toBe($this->team->id);
});

it('adds a Slack channel and refuses a URL that is not Slack\'s', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'slack', 'name' => '#alerts', 'config' => ['url' => 'https://example.com/hook'],
        ])
        ->assertSessionHasErrors('config.url');

    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'slack', 'name' => '#alerts', 'config' => ['url' => SLACK_URL],
        ])
        ->assertSessionHasNoErrors();

    expect(NotificationChannel::query()->sole()->config)->toBe(['url' => SLACK_URL]);
});

it('generates a webhook secret and shows it exactly once', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'webhook', 'name' => 'Incident bridge', 'config' => ['url' => 'https://ops.acme.dev/hooks/bilis', 'secret' => ''],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('channelSecret.name', 'Incident bridge');

    $channel = NotificationChannel::query()->sole();

    expect($channel->config['secret'])->toStartWith('whsec_');

    $this->actingAs($this->owner)
        ->get(route('teams.edit', ['team' => 'acme']))
        ->assertDontSee($channel->config['secret']);
});

it('refuses a malformed signing secret', function () {
    $this->actingAs($this->owner)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), [
            'type' => 'webhook', 'name' => 'Bridge', 'config' => ['url' => 'https://ops.acme.dev/hooks', 'secret' => 'hunter2'],
        ])
        ->assertSessionHasErrors('config.secret');
});

it('keeps the stored URL and secret when an edit leaves them blank', function () {
    $secret = 'whsec_'.base64_encode(str_repeat('s', 24));
    $channel = NotificationChannel::factory()->for($this->team)->webhook()->create([
        'config' => ['url' => 'https://ops.acme.dev/hooks/bilis', 'secret' => $secret],
    ]);

    $this->actingAs($this->owner)
        ->patch(route('teams.notification-channels.update', ['team' => 'acme', 'notificationChannel' => $channel->id]), [
            'type' => 'slack', // ignored: a channel's type is fixed
            'name' => 'Renamed bridge',
            'enabled' => true,
            'issue_events' => true,
            'config' => ['url' => '', 'secret' => ''],
        ])
        ->assertSessionHasNoErrors();

    $channel->refresh();

    expect($channel->name)->toBe('Renamed bridge')
        ->and($channel->type)->toBe(NotificationChannelType::Webhook)
        ->and($channel->config)->toBe(['url' => 'https://ops.acme.dev/hooks/bilis', 'secret' => $secret])
        ->and($channel->issue_events)->toBeTrue();
});

it('switches a channel off and on, and removes it', function () {
    $channel = NotificationChannel::factory()->for($this->team)->create();
    $toggle = route('teams.notification-channels.toggle', ['team' => 'acme', 'notificationChannel' => $channel->id]);

    $this->actingAs($this->owner)->post($toggle)->assertRedirect();
    expect($channel->fresh()->enabled)->toBeFalse();

    $this->actingAs($this->owner)->post($toggle)->assertRedirect();
    expect($channel->fresh()->enabled)->toBeTrue();

    $this->actingAs($this->owner)
        ->delete(route('teams.notification-channels.destroy', ['team' => 'acme', 'notificationChannel' => $channel->id]))
        ->assertRedirect(route('teams.edit', ['team' => 'acme']));

    expect($channel->fresh())->toBeNull();
});

it('lets only owners and admins change channels', function () {
    $channel = NotificationChannel::factory()->for($this->team)->create();

    $this->actingAs($this->member)
        ->post(route('teams.notification-channels.store', ['team' => 'acme']), ['type' => 'email', 'name' => 'x', 'config' => ['recipients' => 'members']])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->patch(route('teams.notification-channels.update', ['team' => 'acme', 'notificationChannel' => $channel->id]), ['name' => 'x'])
        ->assertForbidden();

    $this->actingAs($this->member)
        ->post(route('teams.notification-channels.toggle', ['team' => 'acme', 'notificationChannel' => $channel->id]))
        ->assertForbidden();

    $this->actingAs($this->member)
        ->post(route('teams.notification-channels.test', ['team' => 'acme', 'notificationChannel' => $channel->id]))
        ->assertForbidden();

    $this->actingAs($this->member)
        ->delete(route('teams.notification-channels.destroy', ['team' => 'acme', 'notificationChannel' => $channel->id]))
        ->assertForbidden();

    expect(NotificationChannel::query()->count())->toBe(1);
});

it('is a 404 for another team\'s channel', function () {
    $foreign = NotificationChannel::factory()->for($this->otherTeam)->create();

    $this->actingAs($this->owner)
        ->patch("/settings/teams/acme/notification-channels/{$foreign->id}", ['name' => 'mine now', 'config' => ['recipients' => 'members']])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->post("/settings/teams/acme/notification-channels/{$foreign->id}/test")
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->delete("/settings/teams/acme/notification-channels/{$foreign->id}")
        ->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('mine now');
});

describe('send test', function () {
    it('sends a test now and says so', function () {
        Notification::fake();
        $channel = NotificationChannel::factory()->for($this->team)->failing()->create(['config' => ['addresses' => ['oncall@acme.dev']]]);

        $this->actingAs($this->owner)
            ->postJson(route('teams.notification-channels.test', ['team' => 'acme', 'notificationChannel' => $channel->id]))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'Test notification sent.']);

        Notification::assertSentOnDemand(AlertNotification::class, fn (AlertNotification $notification): bool => $notification->eventId === null
            && $notification->channelId === $channel->id);

        expect($channel->fresh()->last_error)->toBeNull();
    });

    it('reports a failure in words written for the reader', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create(['config' => ['addresses' => []]]);

        $this->actingAs($this->owner)
            ->postJson(route('teams.notification-channels.test', ['team' => 'acme', 'notificationChannel' => $channel->id]))
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'The channel has no email recipients.']);
    });

    it('will not pretend to test a channel that is switched off', function () {
        Notification::fake();
        $channel = NotificationChannel::factory()->for($this->team)->disabled()->create();

        $this->actingAs($this->owner)
            ->postJson(route('teams.notification-channels.test', ['team' => 'acme', 'notificationChannel' => $channel->id]))
            ->assertOk()
            ->assertJson(['ok' => false]);

        Notification::assertNothingSent();
    });
});
