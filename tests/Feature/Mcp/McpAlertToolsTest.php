<?php

use App\Enums\TeamRole;
use App\Mcp\Servers\BilisServer;
use App\Mcp\Tools\ListAlertsTool;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
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
        // Two days old: outside the default window.
        ->assertDontSee('"type":"resolved"');

    // Nothing here reads ClickHouse.
    Http::assertNothingSent();
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
