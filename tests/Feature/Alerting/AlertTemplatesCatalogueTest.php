<?php

use App\Enums\TeamRole;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerting\Templates\AlertProvisioner;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Alerts page's Templates tab: the whole catalogue with each template's
 * status for the team, and turning one on by hand — installed without waiting
 * for its data, or a turned-off rule switched back on. Uses the real catalogue.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    Project::factory()->forTeam($this->team)->create(['slug' => 'shop']);

    $this->templates = app(AlertProvisioner::class)->templates();
});

it('lists every template with its status for the team', function () {
    AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'Error spike',
        'template_key' => 'logs.error_spike',
        'template_version' => 1,
        'enabled' => true,
    ]);
    $off = AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'High error rate',
        'template_key' => 'traces.error_rate',
        'template_version' => 1,
        'enabled' => false,
        'customized_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('alerts.templates.index', ['current_team' => 'acme']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('alerts/Index')
            ->where('tab', 'templates')
            ->has('templates', count($this->templates))
            ->where('templates', function ($templates) use ($off): bool {
                $byKey = collect($templates)->keyBy('key');

                return $byKey['logs.error_spike']['status'] === 'on'
                    && $byKey['traces.error_rate']['status'] === 'off'
                    && $byKey['traces.error_rate']['ruleId'] === $off->id
                    && $byKey['traces.error_rate']['customized'] === true
                    && $byKey['hosts.disk_full']['status'] === 'available'
                    && $byKey['hosts.disk_full']['ruleId'] === null;
            }));
});

it('installs a template that is not set up yet, linked to the issue-alert channels', function () {
    $issues = NotificationChannel::factory()->create(['team_id' => $this->team->id, 'enabled' => true, 'issue_events' => true]);
    NotificationChannel::factory()->create(['team_id' => $this->team->id, 'enabled' => true, 'issue_events' => false]);

    $this->actingAs($this->user)
        ->post(route('alerts.templates.activate', ['current_team' => 'acme', 'template' => 'hosts.disk_full']))
        ->assertRedirect();

    $rule = AlertRule::query()->where('team_id', $this->team->id)->where('template_key', 'hosts.disk_full')->sole();

    expect($rule->enabled)->toBeTrue()
        ->and($rule->project_ids)->toBeNull()
        ->and($rule->name)->toBe($this->templates['hosts.disk_full']['name'])
        ->and($rule->template_version)->toBe($this->templates['hosts.disk_full']['version'])
        ->and($rule->customized_at)->toBeNull()
        ->and($rule->notificationChannels()->pluck('notification_channels.id')->all())->toBe([$issues->id]);
});

it('switches a turned-off template rule back on without touching its definition', function () {
    $rule = AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'My tuned spike',
        'template_key' => 'logs.error_spike',
        'template_version' => 1,
        'threshold' => 5,
        'enabled' => false,
        'customized_at' => now(),
        'next_evaluation_at' => now()->addHour(),
    ]);

    $this->actingAs($this->user)
        ->post(route('alerts.templates.activate', ['current_team' => 'acme', 'template' => 'logs.error_spike']))
        ->assertRedirect();

    $rule->refresh();

    expect($rule->enabled)->toBeTrue()
        ->and($rule->next_evaluation_at)->toBeNull()
        ->and($rule->name)->toBe('My tuned spike')
        ->and((float) $rule->threshold)->toBe(5.0)
        ->and(AlertRule::query()->where('team_id', $this->team->id)->count())->toBe(1);
});

it('leaves a rule that is already on alone', function () {
    AlertRule::factory()->forTeam($this->team)->create([
        'template_key' => 'logs.error_spike',
        'template_version' => 1,
        'enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->post(route('alerts.templates.activate', ['current_team' => 'acme', 'template' => 'logs.error_spike']))
        ->assertRedirect();

    expect(AlertRule::query()->where('team_id', $this->team->id)->count())->toBe(1);
});

it('answers 404 for a template that is not in the catalogue', function () {
    $this->actingAs($this->user)
        ->post(route('alerts.templates.activate', ['current_team' => 'acme', 'template' => 'logs.nope']))
        ->assertNotFound();

    expect(AlertRule::query()->count())->toBe(0);
});

it('only turns templates on in the current team', function () {
    $other = Team::factory()->create(['slug' => 'other']);

    $this->actingAs($this->user)
        ->post(route('alerts.templates.activate', ['current_team' => 'other', 'template' => 'logs.error_spike']))
        ->assertStatus(403);

    expect(AlertRule::query()->where('team_id', $other->id)->count())->toBe(0);
});
