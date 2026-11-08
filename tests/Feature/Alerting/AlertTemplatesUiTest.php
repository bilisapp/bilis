<?php

use App\Enums\AlertState;
use App\Enums\TeamRole;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Services\Alerting\AlertMessage;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Template rules in the UI and in notifications (specs/alerting-defaults.md
 * §4): the "Set up by Bilis" facts, customisation on a real edit only,
 * delete as turn-off, and the notification footer.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:37', 'UTC'));

    config(['alerting_templates.templates' => [
        'logs.error_spike' => [
            'version' => 1,
            'requires' => 'logs',
            'name' => 'Error spike',
            'why' => 'A service logged three times its usual errors.',
            'rule' => [],
        ],
    ]]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->project = Project::factory()->forTeam($this->team)->create(['slug' => 'shop']);

    $this->template = AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'Error spike',
        'project_ids' => null,
        'query' => [
            'filters' => ['severities' => ['error', 'fatal']],
            'aggregation' => 'count',
            'group_by' => 'service.name',
            'compare' => ['offset_seconds' => 604_800],
            'min_events' => 20,
        ],
        'threshold' => 3,
        'window_seconds' => 600,
        'interval_seconds' => 300,
        'keep_firing_for_seconds' => 600,
        'template_key' => 'logs.error_spike',
        'template_version' => 1,
    ]);

    $this->userRule = AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'Checkout errors',
        'project_ids' => [$this->project->id],
    ]);
});

/**
 * The template rule exactly as the editor posts it back unchanged: the form
 * sends empty fields and its own key order.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function templatePayload(array $overrides = []): array
{
    return [
        'name' => 'Error spike',
        'project_ids' => null,
        'source' => 'logs',
        'query' => [
            'min_events' => 20,
            'compare' => ['offset_seconds' => 604800],
            'aggregation' => 'count',
            'group_by' => 'service.name',
            'filters' => ['services' => [], 'severities' => ['error', 'fatal'], 'search' => ''],
        ],
        'condition' => '>',
        'threshold' => '3',
        'window_seconds' => 600,
        'interval_seconds' => 300,
        'for_seconds' => 0,
        'keep_firing_for_seconds' => 600,
        'no_data_state' => 'inactive',
        'severity' => 'warning',
        'channel_ids' => [],
        ...$overrides,
    ];
}

/**
 * @return array{0: AlertMessage, 1: AlertNotification}
 */
function templateMessage(AlertRule $rule): array
{
    $at = CarbonImmutable::parse('2026-11-08 11:55:00', 'UTC');
    $event = AlertEvent::factory()->forRule($rule)->create([
        'labels' => ['service.name' => 'checkout'],
        'value' => 4.2,
        'occurred_at' => $at,
        'context' => ['window' => ['from' => $at->subMinutes(10)->toIso8601ZuluString(), 'to' => $at->toIso8601ZuluString()]],
    ]);
    $channel = NotificationChannel::factory()->for($rule->team)->create();
    $notification = new AlertNotification($channel->id, $channel->type, $event->id);

    return [$notification->message(), $notification];
}

describe('the alerts page', function () {
    it('marks template rules, carries their why, and counts the enabled ones for the banner', function () {
        AlertRule::factory()->forTeam($this->team)->disabled()->create(['template_key' => 'hosts.disk_full', 'template_version' => 1]);

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/Index')
                ->where('templateCount', 1)
                ->where('rules', fn ($rules) => collect($rules)->firstWhere('id', $this->template->id)['template'] === true
                    && collect($rules)->firstWhere('id', $this->template->id)['why'] === 'A service logged three times its usual errors.'
                    && collect($rules)->firstWhere('id', $this->template->id)['customized'] === false
                    && collect($rules)->firstWhere('id', $this->userRule->id)['template'] === false
                    && collect($rules)->firstWhere('id', $this->userRule->id)['why'] === null
                    // A template since removed from the catalogue has no why, but is still marked.
                    && collect($rules)->where('template', true)->count() === 2
                    && collect($rules)->where('template', true)->whereNull('why')->count() === 1
                ),
            );
    });

    it('gives the editor the template facts', function () {
        $this->actingAs($this->user)
            ->get(route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $this->template->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule.template', true)
                ->where('rule.why', 'A service logged three times its usual errors.')
                ->where('rule.customized', false),
            );
    });
});

describe('editing a template rule', function () {
    it('does not count an unchanged save, or new channels, as customising', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create();

        $this->actingAs($this->user)
            ->put(route('alerts.update', ['current_team' => 'acme', 'alertRule' => $this->template->id]), templatePayload(['channel_ids' => [$channel->id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->template->refresh();

        expect($this->template->customized_at)->toBeNull()
            ->and($this->template->notificationChannels->modelKeys())->toBe([$channel->id]);
    });

    it('does not count mute or enable as customising', function () {
        $this->actingAs($this->user)->post(route('alerts.mute', ['current_team' => 'acme', 'alertRule' => $this->template->id]), ['until' => '1h']);
        $this->actingAs($this->user)->post(route('alerts.toggle', ['current_team' => 'acme', 'alertRule' => $this->template->id]));

        expect($this->template->refresh()->customized_at)->toBeNull();
    });

    it('stamps customized_at when the rule itself changes', function (array $change) {
        $this->actingAs($this->user)
            ->put(route('alerts.update', ['current_team' => 'acme', 'alertRule' => $this->template->id]), templatePayload($change))
            ->assertSessionHasNoErrors();

        expect($this->template->refresh()->customized_at?->toIso8601String())->toBe(now()->toIso8601String());
    })->with([
        'threshold' => [['threshold' => 4]],
        'name' => [['name' => 'Our error spike']],
        'min events' => [['query' => [
            'aggregation' => 'count', 'group_by' => 'service.name', 'compare' => ['offset_seconds' => 604800], 'min_events' => 50,
            'filters' => ['severities' => ['error', 'fatal']],
        ]]],
        'projects' => [fn () => ['project_ids' => [Project::query()->where('slug', 'shop')->value('id')]]],
    ]);

    it('never stamps a rule a person built', function () {
        $this->actingAs($this->user)
            ->put(route('alerts.update', ['current_team' => 'acme', 'alertRule' => $this->userRule->id]), templatePayload(['threshold' => 99, 'project_ids' => [$this->project->id]]))
            ->assertSessionHasNoErrors();

        expect($this->userRule->refresh()->customized_at)->toBeNull();
    });
});

describe('deleting', function () {
    it('turns a template rule off and keeps the row', function () {
        AlertInstance::factory()->firing()->create(['alert_rule_id' => $this->template->id]);

        $this->actingAs($this->user)
            ->delete(route('alerts.destroy', ['current_team' => 'acme', 'alertRule' => $this->template->id]))
            ->assertRedirect(route('alerts.index', ['current_team' => 'acme']))
            ->assertInertiaFlash('toast.message', 'Alert rule turned off.');

        $rule = AlertRule::query()->find($this->template->id);

        expect($rule)->not->toBeNull()
            ->and($rule->enabled)->toBeFalse()
            ->and($rule->template_key)->toBe('logs.error_spike')
            ->and($rule->customized_at)->toBeNull();
    });

    it('really deletes a rule a person built', function () {
        $this->actingAs($this->user)
            ->delete(route('alerts.destroy', ['current_team' => 'acme', 'alertRule' => $this->userRule->id]))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Alert rule deleted.');

        expect(AlertRule::query()->find($this->userRule->id))->toBeNull();
    });
});

describe('notifications', function () {
    it('ends a template rule\'s mail and Slack message with how to tune or turn it off', function () {
        [$message, $notification] = templateMessage($this->template);
        $editUrl = route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $this->template->id]);
        $footer = "Bilis set this alert up automatically. Tune it or turn it off: {$editUrl}";

        $mailLines = $notification->toMail(new stdClass)->introLines;
        $outro = $notification->toMail(new stdClass)->outroLines;
        $lines = [...$mailLines, ...$outro];

        expect($message->footer)->toBe($footer)
            ->and(end($lines))->toBe($footer)
            ->and(SlackWebhookChannel::text($message))->toEndWith("\n".$footer)
            ->and($message->payload['rule']['template_key'])->toBe('logs.error_spike');
    });

    it('adds nothing for a rule a person built, and its webhook template_key is null', function () {
        [$message, $notification] = templateMessage($this->userRule);
        $lines = [...$notification->toMail(new stdClass)->introLines, ...$notification->toMail(new stdClass)->outroLines];

        expect($message->footer)->toBeNull()
            ->and(implode("\n", $lines))->not->toContain('set this alert up automatically')
            ->and(SlackWebhookChannel::text($message))->not->toContain('set this alert up automatically')
            ->and($message->payload['rule'])->toHaveKey('template_key', null);
    });
});

it('keeps instance states untouched by turning a template off', function () {
    $instance = AlertInstance::factory()->firing()->create(['alert_rule_id' => $this->template->id]);

    $this->actingAs($this->user)->delete(route('alerts.destroy', ['current_team' => 'acme', 'alertRule' => $this->template->id]));

    // Like switching a rule off: the badge and "Needs attention" skip a disabled rule's series.
    expect($instance->refresh()->state)->toBe(AlertState::Firing);
});
