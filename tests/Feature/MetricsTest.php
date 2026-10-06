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
            str_contains($body, 'ARRAY JOIN mapKeys(mapUpdate(') => jsonEachRow([
                ['Key' => 'http.route', 'Values' => ['/checkout', '/cart']],
            ]),
            str_contains($body, 'AS Total') => jsonEachRow([['Total' => 2]]),
            // One-minute buckets (the metrics minimum): 10:59 is the baseline,
            // 11:00 the second bucket, the points a minute apart.
            str_contains($body, 'AS Start') => jsonEachRow([
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 100, 'Start' => $at('10:00:00')],
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 280, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 10, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 70, 'Start' => $at('10:00:00')],
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
                ->where('series.intervalSeconds', 60)
                // 11:00 is the second bucket: (280 - 100) over the 60 s between the points.
                ->where('series.series.0.label', '/checkout')
                ->where('series.series.0.points.1', 3)
                ->where('series.series.1.label', '/cart')
                ->where('series.series.1.points.1', 1)
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

/**
 * Answer the Hosts tab's statements by shape: one host, `web-1`, whose CPU
 * counter is the only chart metric with data besides the load gauge.
 */
function fakeHostsClickHouse(bool $hasHosts = true): void
{
    $at = fn (string $time): int => Carbon::parse("2026-09-25 {$time}", 'UTC')->getTimestamp();

    Http::fake(function (Request $request) use ($at, $hasHosts) {
        $body = $request->body();

        return Http::response(match (true) {
            str_contains($body, 'MetricName = {metric:String} LIMIT 1') => $hasHosts ? jsonEachRow([['1' => 1]]) : '',
            str_contains($body, 'AS LastSeen') => jsonEachRow([['Host' => 'web-1', 'LastSeen' => $at('11:00:30')]]),
            str_contains($body, "'cpu' AS Stat") => jsonEachRow([
                ['Host' => 'web-1', 'Stat' => 'cpu', 'V' => 0.25, 'Label' => ''],
                ['Host' => 'web-1', 'Stat' => 'memory', 'V' => 0.75, 'Label' => ''],
                ['Host' => 'web-1', 'Stat' => 'disk', 'V' => 0.8, 'Label' => '/'],
                ['Host' => 'web-1', 'Stat' => 'load', 'V' => 1.5, 'Label' => ''],
            ]),
            str_contains($body, 'AS Points') => jsonEachRow([
                ['Name' => 'system.cpu.time', 'Type' => 'sum', 'Unit' => 's', 'Description' => '', 'Services' => ['host'], 'Monotonic' => true, 'Temporality' => 2, 'Points' => 240],
                ['Name' => 'system.cpu.load_average.1m', 'Type' => 'gauge', 'Unit' => '{thread}', 'Description' => '', 'Services' => ['host'], 'Monotonic' => false, 'Temporality' => 0, 'Points' => 60],
            ]),
            str_contains($body, 'AS Total') => jsonEachRow([['Total' => 2]]),
            str_contains($body, 'AS Start') => jsonEachRow([
                ['S' => '1', 'Grp' => 'user', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 100, 'Start' => $at('09:00:00')],
                ['S' => '1', 'Grp' => 'user', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 130, 'Start' => $at('09:00:00')],
                ['S' => '2', 'Grp' => 'idle', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 200, 'Start' => $at('09:00:00')],
                ['S' => '2', 'Grp' => 'idle', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 230, 'Start' => $at('09:00:00')],
            ]),
            str_contains($body, 'AS W FROM (') => jsonEachRow([
                ['Bucket' => $at('11:00:00'), 'Grp' => '', 'V' => 1.5, 'W' => 1],
            ]),
            default => '',
        });
    });
}

test('the hosts tab lists every host and charts the first one', function () {
    [$user, $team] = metricsTeam();
    fakeHostsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.hosts', [
            'current_team' => $team->slug,
            'from' => '2026-09-25T10:59:00Z',
            'to' => '2026-09-25T11:01:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('metrics/Hosts')
            ->where('hasHosts', true)
            ->where('filters.host', null)
            ->missing('hosts')
            ->missing('charts')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('hosts.hosts.0.name', 'web-1')
                ->where('hosts.hosts.0.cpu', 0.25)
                ->where('hosts.hosts.0.disk', 0.8)
                ->where('hosts.hosts.0.diskMount', '/')
                ->where('hosts.hosts.0.containers', 0)
                ->where('hosts.hosts.0.lastSeen', '2026-09-25T11:00:30+00:00')
                ->where('charts.host', 'web-1')
                ->where('charts.charts.0.id', 'cpu')
                // No utilization gauge in the catalog: the counter, as cores busy.
                ->where('charts.charts.0.metric', 'system.cpu.time')
                ->where('charts.charts.0.where', ['host.name' => 'web-1'])
                ->where('charts.charts.0.series.unit', '{core}')
                ->where('charts.charts.0.series.kind', 'value')
                ->where('charts.charts.0.series.series.0.points.1', 0.5)
                ->where('charts.charts.1.id', 'load')
                ->has('charts.charts', 2)
                ->etc()
            )
        );

    // Every chart is filtered to the host, through the resource fallback.
    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'AS Start')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($query['param_whereKey0'])->toBe('host.name')
            ->and($query['param_whereValue0'])->toBe('web-1')
            ->and($request->body())->toContain('ResourceAttributes[{whereKey0:String}]');

        return true;
    });
});

test('a host that did not report draws no charts', function () {
    [$user, $team] = metricsTeam();
    fakeHostsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.hosts', ['current_team' => $team->slug, 'host' => 'gone-1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.host', 'gone-1')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('charts.host', null)
                ->where('charts.charts', [])
                ->etc()
            ));

    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'AS Total'));
});

test('the hosts tab reads only the team\'s own projects', function () {
    [$user, $team, $project] = metricsTeam();
    $other = Project::factory()->create();
    fakeHostsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.hosts', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('hosts')->etc()));

    Http::assertSent(function (Request $request) use ($project, $other) {
        if (! str_contains($request->body(), 'AS LastSeen')) {
            return false;
        }

        expect(clickHouseQuery($request)['param_projectIds'])->toBe("['".$project->id."']")
            ->not->toContain((string) $other->id);

        return true;
    });
});

test('a bare visit to metrics opens on hosts when the team has any', function () {
    [$user, $team] = metricsTeam();
    fakeHostsClickHouse();

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('metrics.hosts', ['current_team' => $team->slug]));

    // The Explorer tab always carries a window, so it is never sent away.
    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug, 'from' => '2026-09-25T11:00:00Z', 'to' => '2026-09-25T12:00:00Z']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('metrics/Index'));
});

test('a bare visit stays on the explorer for a team without hosts', function () {
    [$user, $team] = metricsTeam();
    fakeHostsClickHouse(hasHosts: false);

    $this->actingAs($user)
        ->get(route('metrics.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('metrics/Index'));

    $this->actingAs($user)
        ->get(route('metrics.hosts', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasHosts', false));
});

test('the hosts tab is for members only', function () {
    [, $team] = metricsTeam();

    $this->get(route('metrics.hosts', ['current_team' => $team->slug]))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('metrics.hosts', ['current_team' => $team->slug]))
        ->assertForbidden();
});
