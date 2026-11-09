<?php

use App\Enums\AlertEventType;
use App\Enums\AlertRuleHealth;
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
use App\Services\Alerting\AlertBadge;
use App\Services\Alerting\Labels;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\Alerting\Sources\LogAlertSource;
use App\Services\ClickHouse\ClickHouseException;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAlertSource;

/*
 * The Alerts page, the rule editor and its preview (specs/alerting.md,
 * "Plans, MCP, UI"). The preview's source is faked; the sources' SQL is
 * proven in AlertSourcesLiveTest.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:37', 'UTC'));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['slug' => 'acme']);
    $this->team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);
    $this->project = Project::factory()->forTeam($this->team)->create(['name' => 'Shop', 'slug' => 'shop']);

    $this->otherTeam = Team::factory()->create(['slug' => 'globex']);
    $this->otherProject = Project::factory()->forTeam($this->otherTeam)->create();
});

/**
 * A valid rule as the editor posts it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function alertRulePayload(Project $project, array $overrides = []): array
{
    return [
        'name' => 'Checkout error spike',
        'project_ids' => [$project->id],
        'source' => 'logs',
        'query' => [
            'filters' => ['services' => ['checkout'], 'severities' => ['error', 'fatal'], 'search' => ''],
            'aggregation' => 'count',
            'group_by' => null,
        ],
        'condition' => '>',
        'threshold' => 25,
        'window_seconds' => 300,
        'interval_seconds' => 60,
        'for_seconds' => 0,
        'keep_firing_for_seconds' => 0,
        'no_data_state' => 'inactive',
        'severity' => 'warning',
        'channel_ids' => [],
        ...$overrides,
    ];
}

function fakePreviewSource(string $key = 'logs'): FakeAlertSource
{
    config(['alerting.sources' => [$key => FakeAlertSource::class]]);
    $source = new FakeAlertSource;
    app()->instance(FakeAlertSource::class, $source);

    return $source;
}

describe('index', function () {
    it('lists firing series before pending ones, and every rule with its health and channels', function () {
        $channel = NotificationChannel::factory()->for($this->team)->slack()->create(['name' => '#alerts']);
        $rule = AlertRule::factory()->forTeam($this->team)->create(['name' => 'Checkout error spike', 'project_ids' => [$this->project->id]]);
        $rule->notificationChannels()->attach($channel);
        $failing = AlertRule::factory()->forTeam($this->team)->failing()->create(['name' => 'Auth failures']);
        AlertRule::factory()->forTeam($this->otherTeam)->create(['name' => 'Someone else']);

        AlertInstance::factory()->for($rule, 'rule')->create([
            'labels' => ['service.name' => 'billing'], 'labels_hash' => Labels::hash(['service.name' => 'billing']),
            'state' => AlertState::Pending, 'value' => 30, 'active_at' => now()->subMinutes(2),
        ]);
        AlertInstance::factory()->for($rule, 'rule')->create([
            'labels' => ['service.name' => 'checkout'], 'labels_hash' => Labels::hash(['service.name' => 'checkout']),
            'state' => AlertState::Firing, 'value' => 87, 'active_at' => now()->subMinutes(12), 'fired_at' => now()->subMinutes(12),
        ]);
        AlertInstance::factory()->for($rule, 'rule')->create([
            'labels' => ['service.name' => 'search'], 'labels_hash' => Labels::hash(['service.name' => 'search']),
            'state' => AlertState::Inactive,
        ]);

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/Index')
                ->where('tab', 'rules')
                ->where('hasChannels', true)
                ->where('ruleCount', 2)
                ->has('instances', 2)
                ->where('instances.0.state', 'firing')
                ->where('instances.0.labels', ['service.name' => 'checkout'])
                ->where('instances.0.ruleName', 'Checkout error spike')
                ->where('instances.1.state', 'pending')
                ->has('rules', 2)
                ->where('rules.0.name', 'Auth failures')
                ->where('rules.0.health', 'err')
                ->where('rules.0.lastError', 'ClickHouse timed out after 10s')
                ->where('rules.1.name', 'Checkout error spike')
                ->where('rules.1.firing', 1)
                ->where('rules.1.pending', 1)
                ->where('rules.1.channels.0.name', '#alerts')
                ->where('overdue', 0)
            );

        expect($failing->exists)->toBeTrue();
    });

    it('says when there is no rule yet and nowhere to send', function () {
        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ruleCount', 0)
                ->where('hasChannels', false)
                ->where('hasProjects', true)
                ->has('rules', 0)
                ->has('instances', 0)
            );
    });

    it('leaves a disabled rule\'s stale series out of firing and pending', function () {
        $disabled = AlertRule::factory()->forTeam($this->team)->disabled()->create(['name' => 'Turned off']);

        AlertInstance::factory()->for($disabled, 'rule')->create([
            'labels' => ['service.name' => 'checkout'], 'labels_hash' => Labels::hash(['service.name' => 'checkout']),
            'state' => AlertState::Firing, 'value' => 87, 'active_at' => now()->subMinutes(12), 'fired_at' => now()->subMinutes(12),
        ]);

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rules', 1)
                ->has('instances', 0)
            );
    });

    it('counts rules that are more than one interval overdue', function () {
        AlertRule::factory()->forTeam($this->team)->create(['next_evaluation_at' => now()->subMinutes(5), 'interval_seconds' => 60]);
        AlertRule::factory()->forTeam($this->team)->create(['next_evaluation_at' => now()->subSeconds(30), 'interval_seconds' => 60]);
        AlertRule::factory()->forTeam($this->team)->disabled()->create(['next_evaluation_at' => now()->subHour()]);

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertInertia(fn (Assert $page) => $page->where('overdue', 1));
    });

    it('is only for members of the team', function () {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertForbidden();
    });

    it('lists the team history newest first, with the link a notification carried', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->create(['name' => 'Checkout error spike', 'project_ids' => [$this->project->id]]);

        foreach ([[AlertEventType::Firing, 30, 87.0], [AlertEventType::Resolved, 10, 3.0]] as [$type, $minutes, $value]) {
            AlertEvent::factory()->create([
                'team_id' => $this->team->id,
                'alert_rule_id' => $rule->id,
                'type' => $type,
                'labels' => ['service.name' => 'checkout'],
                'value' => $value,
                'threshold' => 25,
                'occurred_at' => now()->subMinutes($minutes)->startOfMinute(),
                'context' => ['window' => [
                    'from' => now()->subMinutes($minutes + 5)->startOfMinute()->toIso8601ZuluString(),
                    'to' => now()->subMinutes($minutes)->startOfMinute()->toIso8601ZuluString(),
                ]],
                'dedupe_key' => "test:{$type->value}",
            ]);
        }

        AlertEvent::factory()->create(['team_id' => $this->otherTeam->id, 'dedupe_key' => 'other']);

        $this->actingAs($this->user)
            ->get(route('alerts.history', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/Index')
                ->where('tab', 'history')
                ->has('events', 2)
                ->where('events.0.type', 'resolved')
                ->where('events.0.headline', 'Resolved: the value is back to 3.')
                ->where('events.1.type', 'firing')
                ->where('events.1.ruleName', 'Checkout error spike')
                ->where('events.1.href', fn (string $href): bool => str_contains($href, '/acme/logs?') && str_contains($href, 'service=checkout'))
            );
    });
});

describe('editor', function () {
    it('opens a new rule with defaults, the team projects and channels, and what each source offers', function () {
        NotificationChannel::factory()->for($this->team)->create(['name' => 'On-call inbox']);
        NotificationChannel::factory()->for($this->otherTeam)->create(['name' => 'Not ours']);

        $this->actingAs($this->user)
            ->get(route('alerts.create', ['current_team' => 'acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/Edit')
                ->where('rule', null)
                ->where('defaults.source', 'logs')
                ->where('defaults.project_ids', [$this->project->id])
                ->has('projects', 1)
                ->has('channels', 1)
                ->where('channels.0.name', 'On-call inbox')
                ->where('options.traces.aggregations', ['count', 'error_ratio', 'p50', 'p95', 'p99'])
                ->where('options.intervals', [60, 300, 900])
                ->missing('services')
                ->missing('metricCatalog')
            );
    });

    it('opens an existing rule with its fields and what it has firing', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create();
        $rule = AlertRule::factory()->forTeam($this->team)->traces()->create(['project_ids' => [$this->project->id]]);
        $rule->notificationChannels()->attach($channel);
        AlertInstance::factory()->for($rule, 'rule')->create([
            'labels' => ['service.name' => 'payments'], 'labels_hash' => Labels::hash(['service.name' => 'payments']),
            'state' => AlertState::Firing, 'value' => 900, 'fired_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($this->user)
            ->get(route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $rule->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('alerts/Edit')
                ->where('rule.id', $rule->id)
                ->where('rule.source', 'traces')
                ->where('rule.query.aggregation', 'p95')
                ->where('rule.channel_ids', [$channel->id])
                ->has('rule.instances', 1)
                ->where('rule.instances.0.value', 900)
            );
    });

    it('is a 404 for another team\'s rule, or an id that is not one', function (string $id) {
        $foreign = AlertRule::factory()->forTeam($this->otherTeam)->create();

        $this->actingAs($this->user)
            ->get("/acme/alerts/{$id}/edit")
            ->assertNotFound();

        $this->actingAs($this->user)
            ->get("/acme/alerts/{$foreign->id}/edit")
            ->assertNotFound();
    })->with(['abc', '999999']);
});

describe('store and update', function () {
    it('creates a rule that is due now, with its channels and a cleaned query', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create();

        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), alertRulePayload($this->project, ['channel_ids' => [$channel->id]]))
            ->assertRedirect(route('alerts.index', ['current_team' => 'acme']));

        $rule = AlertRule::query()->sole();

        expect($rule->team_id)->toBe($this->team->id)
            ->and($rule->created_by)->toBe($this->user->id)
            ->and($rule->next_evaluation_at)->toBeNull()
            ->and($rule->health)->toBe(AlertRuleHealth::Unknown)
            ->and($rule->enabled)->toBeTrue()
            ->and($rule->project_ids)->toBe([$this->project->id])
            ->and($rule->query)->toBe([
                'filters' => ['services' => ['checkout'], 'severities' => ['error', 'fatal']],
                'aggregation' => 'count',
            ])
            ->and($rule->notificationChannels->modelKeys())->toBe([$channel->id]);
    });

    it('keeps a dotted metric attribute key as one key', function () {
        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), alertRulePayload($this->project, [
                'source' => 'metrics',
                'query' => ['metric' => 'system.cpu.utilization', 'filters' => ['host.name' => 'web-1'], 'aggregation' => 'avg', 'group_by' => 'host.name'],
            ]))
            ->assertSessionHasNoErrors();

        expect(AlertRule::query()->sole()->query)->toEqual([
            'metric' => 'system.cpu.utilization',
            'filters' => ['host.name' => 'web-1'],
            'aggregation' => 'avg',
            'group_by' => 'host.name',
        ]);
    });

    it('saves null project_ids as every project, and a baseline comparison with a volume guard', function () {
        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), alertRulePayload($this->project, [
                'project_ids' => null,
                'query' => [
                    'filters' => ['severities' => ['error', 'fatal']],
                    'aggregation' => 'count',
                    'group_by' => 'service.name',
                    'compare' => ['offset_seconds' => '604800'],
                    'min_events' => '20',
                ],
                'threshold' => 3,
            ]))
            ->assertSessionHasNoErrors();

        $rule = AlertRule::query()->sole();

        expect($rule->project_ids)->toBeNull()
            ->and($rule->query)->toEqual([
                'filters' => ['severities' => ['error', 'fatal']],
                'aggregation' => 'count',
                'group_by' => 'service.name',
                'compare' => ['offset_seconds' => 604800],
                'min_events' => 20,
            ]);

        $this->actingAs($this->user)
            ->get(route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $rule->id]))
            ->assertInertia(fn (Assert $page) => $page->where('rule.project_ids', null));
    });

    it('drops a blank comparison and volume guard from the stored query', function () {
        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), alertRulePayload($this->project, [
                'query' => ['aggregation' => 'count', 'compare' => null, 'min_events' => null],
            ]))
            ->assertSessionHasNoErrors();

        expect(AlertRule::query()->sole()->query)->toBe(['aggregation' => 'count']);
    });

    it('refuses what the rule may not say', function (Closure $payload, string $field) {
        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), $payload($this))
            ->assertSessionHasErrors($field);

        expect(AlertRule::query()->count())->toBe(0);
    })->with([
        'another team\'s project' => [fn ($test) => alertRulePayload($test->project, ['project_ids' => [$test->otherProject->id]]), 'project_ids.0'],
        'no project' => [fn ($test) => alertRulePayload($test->project, ['project_ids' => []]), 'project_ids'],
        'another team\'s channel' => [fn ($test) => alertRulePayload($test->project, ['channel_ids' => [NotificationChannel::factory()->for($test->otherTeam)->create()->id]]), 'channel_ids.0'],
        'a log rule asking for a percentile' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'p95']]), 'query.aggregation'],
        'an unknown severity filter' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'count', 'filters' => ['severities' => ['loud']]]]), 'query.filters.severities.0'],
        'an unknown source' => [fn ($test) => alertRulePayload($test->project, ['source' => 'uptime']), 'source'],
        'a two-minute interval' => [fn ($test) => alertRulePayload($test->project, ['interval_seconds' => 120]), 'interval_seconds'],
        'a 30-second window' => [fn ($test) => alertRulePayload($test->project, ['window_seconds' => 30]), 'window_seconds'],
        'a trace window over an hour' => [fn ($test) => alertRulePayload($test->project, ['source' => 'traces', 'query' => ['aggregation' => 'p95'], 'window_seconds' => 7200]), 'window_seconds'],
        'a for over an hour' => [fn ($test) => alertRulePayload($test->project, ['for_seconds' => 7200]), 'for_seconds'],
        'no name' => [fn ($test) => alertRulePayload($test->project, ['name' => '']), 'name'],
        'no project_ids key at all' => [fn ($test) => array_diff_key(alertRulePayload($test->project), ['project_ids' => true]), 'project_ids'],
        'a two-day comparison' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'count', 'compare' => ['offset_seconds' => 172800]]]), 'query.compare.offset_seconds'],
        'a comparison without an offset' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'count', 'compare' => ['offset' => 86400]]]), 'query.compare.offset_seconds'],
        'a compared log ratio' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'ratio', 'compare' => ['offset_seconds' => 86400]]]), 'query.compare'],
        'a zero volume guard' => [fn ($test) => alertRulePayload($test->project, ['query' => ['aggregation' => 'count', 'min_events' => 0]]), 'query.min_events'],
        'a fractional trace volume guard' => [fn ($test) => alertRulePayload($test->project, ['source' => 'traces', 'query' => ['aggregation' => 'p95', 'min_events' => 2.5]]), 'query.min_events'],
        'a trace comparison a minute back' => [fn ($test) => alertRulePayload($test->project, ['source' => 'traces', 'query' => ['aggregation' => 'p95', 'compare' => ['offset_seconds' => 60]]]), 'query.compare.offset_seconds'],
    ]);

    it('names the window limit of the rule\'s own source', function (string $source, array $query, string $message) {
        $this->actingAs($this->user)
            ->post(route('alerts.store', ['current_team' => 'acme']), alertRulePayload($this->project, [
                'source' => $source, 'query' => $query, 'window_seconds' => 90_000,
            ]))
            ->assertSessionHasErrors(['window_seconds' => $message]);
    })->with([
        'traces' => ['traces', ['aggregation' => 'p95'], 'A trace rule reads at most a one-hour window.'],
        'logs' => ['logs', ['aggregation' => 'count'], 'A rule reads at most a 24-hour window.'],
    ]);

    it('saves changes, makes the rule due now and starts its health over', function () {
        $old = NotificationChannel::factory()->for($this->team)->create();
        $new = NotificationChannel::factory()->for($this->team)->slack()->create();
        $rule = AlertRule::factory()->forTeam($this->team)->failing()->create([
            'project_ids' => [$this->project->id],
            'next_evaluation_at' => now()->addMinutes(10),
        ]);
        $rule->notificationChannels()->attach($old);

        $this->actingAs($this->user)
            ->put(route('alerts.update', ['current_team' => 'acme', 'alertRule' => $rule->id]), alertRulePayload($this->project, [
                'name' => 'Checkout errors, renamed',
                'threshold' => 50,
                'channel_ids' => [$new->id],
            ]))
            ->assertRedirect(route('alerts.index', ['current_team' => 'acme']));

        $rule->refresh();

        expect($rule->name)->toBe('Checkout errors, renamed')
            ->and($rule->threshold)->toBe(50.0)
            ->and($rule->next_evaluation_at)->toBeNull()
            ->and($rule->health)->toBe(AlertRuleHealth::Unknown)
            ->and($rule->last_error)->toBeNull()
            ->and($rule->notificationChannels->modelKeys())->toBe([$new->id]);
    });

    it('will not update or delete another team\'s rule', function () {
        $foreign = AlertRule::factory()->forTeam($this->otherTeam)->create();

        $this->actingAs($this->user)
            ->put("/acme/alerts/{$foreign->id}", alertRulePayload($this->project))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->delete("/acme/alerts/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post("/acme/alerts/{$foreign->id}/mute", ['until' => '1h'])
            ->assertNotFound();

        expect($foreign->fresh())->not->toBeNull();
    });

    it('deletes a rule and keeps its history', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->create();
        $event = AlertEvent::factory()->create(['team_id' => $this->team->id, 'alert_rule_id' => $rule->id, 'dedupe_key' => 'kept']);

        $this->actingAs($this->user)
            ->delete(route('alerts.destroy', ['current_team' => 'acme', 'alertRule' => $rule->id]))
            ->assertRedirect(route('alerts.index', ['current_team' => 'acme']));

        expect(AlertRule::query()->count())->toBe(0)
            ->and($event->fresh())->not->toBeNull();
    });
});

describe('mute and toggle', function () {
    it('mutes for an hour, a day or a week, and unmutes', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->create();
        $url = route('alerts.mute', ['current_team' => 'acme', 'alertRule' => $rule->id]);

        $this->actingAs($this->user)->post($url, ['until' => '1d'])->assertRedirect();
        expect($rule->fresh()->muted_until->equalTo(now()->addDay()))->toBeTrue();

        $this->actingAs($this->user)->post($url, ['until' => '1h'])->assertRedirect();
        expect($rule->fresh()->muted_until->equalTo(now()->addHour()))->toBeTrue();

        $this->actingAs($this->user)->post($url, ['until' => 'off'])->assertRedirect();
        expect($rule->fresh()->muted_until)->toBeNull();

        $this->actingAs($this->user)->post($url, ['until' => 'forever'])->assertSessionHasErrors('until');
    });

    it('disables a rule, and re-enabled it is due at once', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->create(['next_evaluation_at' => now()->addMinutes(3)]);
        $url = route('alerts.toggle', ['current_team' => 'acme', 'alertRule' => $rule->id]);

        $this->actingAs($this->user)->post($url)->assertRedirect();
        expect($rule->fresh()->enabled)->toBeFalse();

        $this->actingAs($this->user)->post($url)->assertRedirect();
        expect($rule->fresh()->enabled)->toBeTrue()
            ->and($rule->fresh()->next_evaluation_at)->toBeNull();
    });
});

describe('preview', function () {
    it('returns the draft\'s series over the last 24 hours, read from the team\'s projects only', function () {
        $source = fakePreviewSource('fake');
        $source->previewLines = [
            ['labels' => ['service.name' => 'checkout'], 'points' => [[1762596000, 3.0], [1762596900, null]]],
        ];

        $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, ['source' => 'fake', 'query' => ['aggregation' => 'avg']]))
            ->assertOk()
            ->assertJson([
                'unavailable' => false,
                'reason' => null,
                'stepSeconds' => 900,
                'windowSeconds' => 300,
                'scaled' => false,
                'series' => [
                    ['labels' => ['service.name' => 'checkout'], 'points' => [[1762596000, 3], [1762596900, null]]],
                ],
            ]);

        expect($source->previewCalls)->toHaveCount(1)
            ->and($source->previewCalls[0]['project_ids'])->toBe([(string) $this->project->id])
            ->and($source->previewCalls[0]['to']->getTimestamp() - $source->previewCalls[0]['from']->getTimestamp())->toBeGreaterThanOrEqual(86400)
            ->and($source->previewCalls[0]['step'])->toBe(900);
    });

    it('scales a count from the bucket width to the rule\'s window', function () {
        fakePreviewSource('logs')->rules = ['query' => ['required', 'array'], 'query.aggregation' => ['required', 'string']];

        $response = $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, ['query' => ['aggregation' => 'count']]))
            ->assertOk()
            ->assertJsonPath('scaled', true);

        // Each 900 s bucket read 1.0; the rule's window is 300 s.
        expect($response->json('series.0.points.0.1'))->toEqualWithDelta(1 / 3, 0.0001);
    });

    it('reads every team project for an all-projects draft, and neither scales nor misaligns a compared count', function () {
        $source = fakePreviewSource('logs');
        $source->rules = app(LogAlertSource::class)->rules();
        $source->previewLines = [['labels' => [], 'points' => [[1762596000, 2.0]]]];
        $later = Project::factory()->forTeam($this->team)->create();

        $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, [
                'project_ids' => null,
                'query' => ['aggregation' => 'count', 'compare' => ['offset_seconds' => 86400]],
                'window_seconds' => 1000,
            ]))
            ->assertOk()
            ->assertJsonPath('scaled', false)
            ->assertJsonPath('series.0.points.0.1', 2);

        expect($source->previewCalls[0]['project_ids'])->toEqualCanonicalizing([(string) $this->project->id, (string) $later->id])
            // 1000 s does not divide a day; the step widens to 1080 s, which does.
            ->and($source->previewCalls[0]['step'])->toBe(1080)
            ->and(86400 % $source->previewCalls[0]['step'])->toBe(0);
    });

    it('says the store is busy without repeating what ClickHouse said', function () {
        $source = fakePreviewSource('fake');
        $source->throw = new AlertSourceUnavailable('ClickHouse could not answer: Code: 241. DB::Exception at 10.0.0.4', previous: new ClickHouseException('boom'));

        $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, ['source' => 'fake']))
            ->assertOk()
            ->assertJson(['unavailable' => true, 'reason' => null, 'series' => []])
            ->assertDontSee('10.0.0.4');
    });

    it('says why a source refused the query', function () {
        $source = fakePreviewSource('fake');
        $source->throw = new AlertSourceUnavailable('system.cpu.utilization is a gauge: a percentile needs a histogram.');

        $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, ['source' => 'fake']))
            ->assertOk()
            ->assertJson(['unavailable' => true, 'reason' => 'system.cpu.utilization is a gauge: a percentile needs a histogram.']);
    });

    it('validates the draft like a saved rule', function () {
        fakePreviewSource('logs');

        $this->actingAs($this->user)
            ->postJson(route('alerts.preview', ['current_team' => 'acme']), alertRulePayload($this->project, ['project_ids' => [$this->otherProject->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_ids.0');
    });
});

describe('sidebar badge', function () {
    it('counts the team\'s firing series in the deferred nav group, and caches it', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->muted()->create();
        $disabled = AlertRule::factory()->forTeam($this->team)->disabled()->create();
        $foreign = AlertRule::factory()->forTeam($this->otherTeam)->create();

        foreach ([[$rule, 'a', AlertState::Firing], [$rule, 'b', AlertState::Firing], [$rule, 'c', AlertState::Pending], [$disabled, 'a', AlertState::Firing], [$foreign, 'a', AlertState::Firing]] as [$owner, $label, $state]) {
            AlertInstance::factory()->for($owner, 'rule')->create(['labels' => ['k' => $label], 'labels_hash' => Labels::hash(['k' => $label]), 'state' => $state]);
        }

        $this->actingAs($this->user)
            ->get(route('alerts.index', ['current_team' => 'acme']))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('alertBadge')
                ->loadDeferredProps('nav', fn (Assert $reload) => $reload->where('alertBadge.firing', 2))
            );

        expect(Cache::get("alerts:badge:{$this->team->id}"))->toBe(['firing' => 2])
            ->and(app(AlertBadge::class)->forTeam($this->team))->toBe(['firing' => 2]);
    });
});

it('links a notification to the rule\'s page', function () {
    $rule = AlertRule::factory()->forTeam($this->team)->create(['project_ids' => [$this->project->id]]);
    $channel = NotificationChannel::factory()->for($this->team)->create();
    $event = AlertEvent::factory()->create([
        'team_id' => $this->team->id,
        'alert_rule_id' => $rule->id,
        'type' => AlertEventType::Firing,
        'value' => 87,
        'threshold' => 25,
        'dedupe_key' => 'link',
    ]);

    $notification = new AlertNotification($channel->id, $channel->type, $event->id);
    $ruleUrl = route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $rule->id]);

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($notification->message()->payload['links']['rule'])->toBe($ruleUrl)
        ->and(array_merge($mail->introLines, $mail->outroLines))->toContain("View the rule: {$ruleUrl}");
});
