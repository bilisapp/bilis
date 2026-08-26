<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

/**
 * Build a dashboard team with two projects and fake the three ClickHouse
 * calls the page makes: hasAnyLogs, the per-project byte scan, and the
 * system.parts total.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: Project}
 */
function storageTeam(): array
{
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $api = Project::factory()->forTeam($team)->create(['name' => 'Api', 'slug' => 'api']);
    $worker = Project::factory()->forTeam($team)->create(['name' => 'Worker', 'slug' => 'worker']);

    return [$user, $team, $api, $worker];
}

test('dashboard reports storage per project, largest first', function () {
    [$user, $team, $api, $worker] = storageTeam();

    Http::fake(function (Request $request) use ($api, $worker) {
        $body = $request->body();

        if (str_contains($body, 'system.parts')) {
            return Http::response(json_encode(['Bytes' => '1000'])."\n");
        }

        if (str_contains($body, 'GROUP BY ProjectId')) {
            return Http::response(
                json_encode(['ProjectId' => (string) $worker->id, 'Rows' => '200', 'Bytes' => '1000'])."\n"
                .json_encode(['ProjectId' => (string) $api->id, 'Rows' => '600', 'Bytes' => '3000'])."\n",
            );
        }

        return Http::response(json_encode(['Present' => 1])."\n");
    });

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage.totalBytes', 1000)
        ->where('storage.unavailable', false)
        ->has('storage.projects', 2)
        ->where('storage.projects.0.name', 'Api')
        ->where('storage.projects.0.slug', 'api')
        ->where('storage.projects.0.rows', 600)
        ->where('storage.projects.0.bytes', 750)
        ->where('storage.projects.1.name', 'Worker')
        ->where('storage.projects.1.bytes', 250),
    );

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'GROUP BY ProjectId')) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && isset($query['param_projectIds']);
    });
});

test('dashboard storage is null for a team with no projects', function () {
    Http::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage', null),
    );

    Http::assertNothingSent();
});

test('an overloaded clickhouse marks storage unavailable without failing the page', function () {
    [$user, $team] = storageTeam();

    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Code: 202. Too many simultaneous queries', 503),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage.unavailable', true)
        ->where('storage.totalBytes', 0)
        ->has('storage.projects', 0),
    );
});
