<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a team with one member, and optionally a project.
 *
 * @return array{0: User, 1: Team, 2: Project|null}
 */
function onboardingTeam(bool $withProject = true): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = $withProject
        ? Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout'])
        : null;

    return [$user, $team, $project];
}

/**
 * The requests that carried the "has this team ever logged?" existence query.
 *
 * @return list<Request>
 */
function existenceRequests(): array
{
    return array_values(array_filter(
        Http::recorded(fn (Request $request) => str_contains($request->body(), 'LIMIT 1'))
            ->map(fn (array $pair): Request => $pair[0])
            ->all(),
    ));
}

/**
 * Pull the query string of a ClickHouse request as an array.
 *
 * @return array<string, string>
 */
function onboardingQuery(Request $request): array
{
    $query = [];
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
    ]);
});

test('a team with no projects is told to create one first', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = onboardingTeam(withProject: false);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('logs/Index')
            ->where('onboarding.stage', 'no-projects')
            ->has('projects', 0),
        );

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), ':8123'));
});

test('a team with a project but no logs is shown the setup snippets', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team, $project] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'no-logs'));

    $requests = existenceRequests();

    expect($requests)->not->toBeEmpty();

    $request = $requests[0];

    expect($request->body())
        ->toContain('SELECT 1 AS Present FROM otel_logs')
        ->toContain('ProjectId IN {projectIds:Array(String)}')
        ->toContain('LIMIT 1');

    expect(onboardingQuery($request)['param_projectIds'])->toBe("['".$project->id."']");
});

test('a team that has logged before gets no onboarding at all', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(json_encode(['Present' => 1])."\n")]);

    [$user, $team] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'ready'));
});

test('the filtered window never drives onboarding', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(json_encode(['Present' => 1])."\n")]);

    [$user, $team] = onboardingTeam();

    // A narrow window with filters returns nothing; the team has still logged
    // before, so the reader gets the normal empty state, not onboarding.
    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'search' => 'nothing-matches-this',
            'from' => '2026-08-26T09:00:00Z',
            'to' => '2026-08-26T09:15:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'ready'));
});

test('an overloaded clickhouse is treated as logs existing', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Code: 202. Too many simultaneous queries', 503),
    ]);

    [$user, $team] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'ready'));
});

test('the existence query only ever names the current team projects', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team, $project] = onboardingTeam();

    $otherTeam = Team::factory()->create();
    $foreign = Project::factory()->forTeam($otherTeam)->create(['slug' => 'not-mine']);

    // Even asking for the other team's project by slug cannot widen the query.
    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'project' => 'not-mine',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'no-logs'));

    foreach (existenceRequests() as $request) {
        expect(onboardingQuery($request)['param_projectIds'])
            ->toBe("['".$project->id."']")
            ->not->toContain((string) $foreign->id);
    }
});

test('once a team has logged the existence query is not repeated', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(json_encode(['Present' => 1])."\n")]);

    [$user, $team] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk();

    // A store that now answers "nothing here" must not demote the team: the
    // positive answer is cached, so the page never flips back to onboarding.
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'ready'));

    expect(existenceRequests())->toBeEmpty();
});

test('the dashboard mirrors the no projects state', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = onboardingTeam(withProject: false);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('onboarding.stage', 'no-projects')
            ->where('firstProject', null),
        );
});

test('the dashboard mirrors the no logs state and names the first project', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.stage', 'no-logs')
            ->where('firstProject.slug', 'checkout')
            ->where('firstProject.name', 'Checkout'),
        );
});

test('the dashboard drops onboarding once logs exist', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(json_encode(['Present' => 1])."\n")]);

    [$user, $team] = onboardingTeam();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('onboarding.stage', 'ready'));
});
