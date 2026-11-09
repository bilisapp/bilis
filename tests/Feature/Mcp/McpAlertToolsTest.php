<?php

use App\Enums\AlertState;
use App\Enums\TeamRole;
use App\Mcp\Servers\BilisServer;
use App\Mcp\Tools\ListAlertsTool;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerting\Labels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * A team with one member, one project and one rule.
 *
 * @return array{0: User, 1: Team, 2: AlertRule}
 */
function mcpAlertsTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Shop', 'slug' => 'shop']);
    $user->switchTeam($team);

    $rule = AlertRule::factory()->forTeam($team)->create([
        'name' => 'Checkout error spike',
        'project_ids' => [$project->id],
    ]);

    return [$user, $team, $rule];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
    Http::fake();
});

test('list-alerts returns firing and pending alerts and the day\'s history', function () {
    [$user, , $rule] = mcpAlertsTeam();

    AlertInstance::factory()->for($rule, 'rule')->pending()->withLabels(['service.name' => 'payments'])->create();
    AlertInstance::factory()->for($rule, 'rule')->firing()->withLabels(['service.name' => 'checkout'])->create();
    AlertInstance::factory()->for($rule, 'rule')->withLabels(['service.name' => 'search'])->create(); // inactive

    AlertEvent::factory()->forRule($rule)->create(['occurred_at' => now()->subMinutes(10), 'dedupe_key' => 'firing']);
    AlertEvent::factory()->forRule($rule)->resolved()->create(['occurred_at' => now()->subDays(2), 'dedupe_key' => 'resolved']);

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertSee('"rule":"Checkout error spike"')
        ->assertSee('"state":"firing"')
        ->assertSee('"labels":{"service.name":"checkout"}')
        ->assertSee('"threshold":25')
        ->assertSee('"since":"2026-11-08T11:50:00+00:00"')
        ->assertSee('"state":"pending"')
        ->assertSee('"from":"2026-11-07T12:00:00+00:00"')
        ->assertSee('"type":"firing"')
        ->assertSee('"activeTruncated":false')
        // Inactive series are not alerts.
        ->assertDontSee('"service.name":"search"')
        // Two days old: outside the default window.
        ->assertDontSee('"type":"resolved"');

    // Nothing here reads ClickHouse.
    Http::assertNothingSent();
});

test('list-alerts leaves out the series of a disabled rule', function () {
    [$user, $team] = mcpAlertsTeam();

    $disabled = AlertRule::factory()->forTeam($team)->create(['name' => 'Turned off', 'enabled' => false]);
    AlertInstance::factory()->for($disabled, 'rule')->firing()->withLabels(['service.name' => 'stale'])->create();

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertDontSee('"service.name":"stale"');
});

test('list-alerts caps active alerts in the query, firing first, and says so', function () {
    [$user, , $rule] = mcpAlertsTeam();

    $rows = [];

    foreach (range(1, 201) as $i) {
        $labels = ['service.name' => "svc-{$i}"];
        $rows[] = [
            'alert_rule_id' => $rule->id,
            'labels' => json_encode($labels),
            'labels_hash' => Labels::hash($labels),
            'state' => $i === 201 ? AlertState::Firing->value : AlertState::Pending->value,
            'value' => 41.0,
            'active_at' => now()->subMinutes(300 - $i),
            'fired_at' => $i === 201 ? now()->subMinute() : null,
            'last_seen_with_data_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    AlertInstance::query()->insert($rows);

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertSee('"activeTruncated":true')
        // The one firing series sorts ahead of 200 older pending ones.
        ->assertSee('"service.name":"svc-201"')
        ->assertSee('"service.name":"svc-1"')
        ->assertDontSee('"service.name":"svc-200"');
});

test('list-alerts reports issue events with the issue they are about', function () {
    [$user, $team] = mcpAlertsTeam();

    AlertEvent::factory()->issueNew()->create(['team_id' => $team->id, 'occurred_at' => now()->subHour()]);

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertSee('"type":"issue_new"')
        ->assertSee('[NEW ISSUE]')
        ->assertSee('"service":"checkout"');
});

test('list-alerts never shows another team\'s alerts', function () {
    [$user] = mcpAlertsTeam();

    $stranger = AlertRule::factory()->create(['name' => 'Somebody else\'s rule']);
    AlertInstance::factory()->for($stranger, 'rule')->firing()->create();
    AlertEvent::factory()->forRule($stranger)->create(['occurred_at' => now()->subMinute()]);

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class)
        ->assertOk()
        ->assertDontSee('Somebody else');

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class, ['team' => $stranger->team->slug])
        ->assertHasErrors()
        ->assertSee('not a member');
});

test('list-alerts bounds the window and the number of events', function () {
    [$user, , $rule] = mcpAlertsTeam();

    foreach (range(1, 3) as $minutes) {
        AlertEvent::factory()->forRule($rule)->create(['occurred_at' => now()->subMinutes($minutes), 'dedupe_key' => "firing-{$minutes}"]);
    }

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class, ['limit' => 2, 'from' => '2026-01-01T00:00:00Z'])
        ->assertOk()
        ->assertSee('"truncated":true')
        // A year asked for, a week read.
        ->assertSee('"from":"2026-11-01T12:00:00+00:00"');

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class, ['limit' => 500])
        ->assertHasErrors();

    BilisServer::actingAs($user)
        ->tool(ListAlertsTool::class, ['state' => 'resolved'])
        ->assertHasErrors();
});

test('list-alerts is announced as read-only', function () {
    expect((new ListAlertsTool)->toArray()['annotations'] ?? [])->toMatchArray(['readOnlyHint' => true]);
});

test('the server registers eleven tools', function () {
    $tools = (new ReflectionClass(BilisServer::class))->getDefaultProperties()['tools'];

    expect($tools)->toHaveCount(11)->toContain(ListAlertsTool::class);
});
