<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Carbon::setTestNow('2026-09-25 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A team with one owner and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function metricsTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

/**
 * JSONEachRow for the given rows.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function jsonEachRow(array $rows): string
{
    return implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
}

/**
 * Answer each of the explorer's statements by its shape: a cumulative counter
 * `http.server.requests` reported by two routes.
 */
function fakeMetricsClickHouse(): void
{
    $at = fn (string $time): int => Carbon::parse("2026-09-25 {$time}", 'UTC')->getTimestamp();

    Http::fake(function (Request $request) use ($at) {
        $body = $request->body();

        return Http::response(match (true) {
            str_contains($body, 'AS Points') => jsonEachRow([[
                'Name' => 'http.server.requests', 'Type' => 'sum', 'Unit' => '{request}', 'Description' => 'Requests served.',
                'Services' => ['checkout'], 'Monotonic' => true, 'Temporality' => 2, 'Points' => 120,
            ]]),
            str_contains($body, 'ARRAY JOIN mapKeys(Attributes)') => jsonEachRow([
                ['Key' => 'http.route', 'Values' => ['/checkout', '/cart']],
            ]),
            str_contains($body, 'AS Total') => jsonEachRow([['Total' => 2]]),
            // 10:59:55 is the baseline, 11:00:00 the 13th five-second bucket.
            str_contains($body, 'AS Start') => jsonEachRow([
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('10:59:55'), 'V' => 100, 'Start' => $at('10:00:00')],
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('11:00:00'), 'V' => 280, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('10:59:55'), 'V' => 10, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('11:00:00'), 'V' => 70, 'Start' => $at('10:00:00')],
            ]),
            str_contains($body, 'SELECT 1 FROM (') => jsonEachRow([['1' => 1]]),
            default => '',
        });
    });
}

test('the explorer renders the catalog, the attributes and the chart', function () {
    [$user, $team] = metricsTeam();
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', [
            'current_team' => $team->slug,
            'metric' => 'http.server.requests',
            'group_by' => 'http.route',
            'from' => '2026-09-25T10:59:00Z',
            'to' => '2026-09-25T11:01:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('metrics/Index')
            ->where('hasMetrics', true)
            ->where('filters.metric', 'http.server.requests')
            ->where('filters.groupBy', 'http.route')
            ->where('filters.agg', 'avg')
            ->has('projects', 1)
            ->missing('series')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('catalog.metrics.0.name', 'http.server.requests')
                ->where('catalog.metrics.0.type', 'sum')
                ->where('catalog.metrics.0.temporality', 2)
                ->where('attributes.attributes.0.key', 'http.route')
                ->where('series.kind', 'rate')
                ->where('series.intervalSeconds', 5)
                // 11:00:00 is the second bucket: (280 - 100) / 5 s.
                ->where('series.series.0.label', '/checkout')
                ->where('series.series.0.points.12', 36)
                ->where('series.series.1.label', '/cart')
                ->where('series.series.1.points.12', 12)
                ->etc()
            )
        );
});

test('every read is scoped to the team\'s own projects and binds every user value', function () {
    [$user, $team, $project] = metricsTeam();
    $other = Project::factory()->create();
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', [
            'current_team' => $team->slug,
            'metric' => 'http.server.requests',
            'service' => 'checkout',
            'group_by' => 'http.route',
            'where' => ['http.route' => "/cart' OR 1=1 --"],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('series')->etc()));

    Http::assertSent(function (Request $request) use ($project, $other) {
        if (! str_contains($request->body(), 'AS Start')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($query['param_projectIds'])->toBe("['".$project->id."']")
            ->and($query['param_projectIds'])->not->toContain((string) $other->id)
            ->and($query['param_metric'])->toBe('http.server.requests')
            ->and($query['param_service'])->toBe('checkout')
            ->and($query['param_groupBy'])->toBe('http.route')
            ->and($query['param_whereKey0'])->toBe('http.route')
            ->and($query['param_whereValue0'])->toBe("/cart' OR 1=1 --")
            ->and($request->body())->not->toContain('OR 1=1')
            ->and($request->body())->toContain('FROM otel_metrics_sum')
            ->and($request->body())->toContain('LIMIT {seriesLimit:UInt32}');

        return true;
    });
});

test('a project the team does not own reads nothing', function () {
    [$user, $team] = metricsTeam();
    Project::factory()->create(['slug' => 'elsewhere']);
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug, 'project' => 'elsewhere', 'metric' => 'http.server.requests']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('catalog.metrics', [])
            ->where('series.kind', null)
            ->etc()
        ));

    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'AS Points'));
});

test('the window is capped at the thirty days the tables keep', function () {
    [$user, $team] = metricsTeam();
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug, 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-09-25T12:00:00Z']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-08-26T12:00:00+00:00')
            ->where('filters.to', '2026-09-25T12:00:00+00:00'));
});

test('no metric selected means no series query', function () {
    [$user, $team] = metricsTeam();
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('series.kind', null)
            ->where('attributes.attributes', [])
            ->etc()
        ));

    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'AS Total'));
});

test('an overloaded clickhouse renders the page with every part unavailable', function () {
    [$user, $team] = metricsTeam();
    Http::fake(['127.0.0.1:8123/*' => Http::response('Too many simultaneous queries', 503)]);

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug, 'metric' => 'http.server.requests']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // An established team must not look brand new because of a hiccup.
            ->where('hasMetrics', true)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('catalog.unavailable', true)
                ->etc()
            ));
});

test('an unknown aggregation is refused', function () {
    [$user, $team] = metricsTeam();
    fakeMetricsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug, 'agg' => 'median']))
        ->assertSessionHasErrors('agg');
});

test('the explorer is for members only', function () {
    [, $team] = metricsTeam();

    $this->get(route('metrics.index', ['current_team' => $team->slug]))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('metrics.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});
