<?php

use App\Enums\TeamRole;
use App\Mcp\Servers\BilisServer;
use App\Mcp\Tools\ListMetricsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\QueryMetricTool;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123, 'clickhouse.database' => 'bilis']);
    Carbon::setTestNow('2026-09-25 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A team with one member and one project named "Checkout".
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function mcpMetricsTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $user->switchTeam($team);

    return [$user, $team, $project];
}

/**
 * JSONEachRow for the given rows.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function mcpMetricRows(array $rows): string
{
    return implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
}

/**
 * Answer each MetricQuery statement by its shape: a cumulative counter
 * `http.server.requests` reported by two routes, and a gauge.
 */
function fakeMcpMetricsClickHouse(): void
{
    $at = fn (string $time): int => Carbon::parse("2026-09-25 {$time}", 'UTC')->getTimestamp();

    Http::fake(function (Request $request) use ($at) {
        $body = $request->body();

        return Http::response(match (true) {
            str_contains($body, 'AS Points') => mcpMetricRows([
                [
                    'Name' => 'http.server.requests', 'Type' => 'sum', 'Unit' => '{request}', 'Description' => 'Requests served.',
                    'Services' => ['checkout'], 'Monotonic' => true, 'Temporality' => 2, 'Points' => 120,
                ],
                [
                    'Name' => 'process.memory.usage', 'Type' => 'gauge', 'Unit' => 'By', 'Description' => 'Resident memory.',
                    'Services' => ['worker'], 'Monotonic' => false, 'Temporality' => 0, 'Points' => 60,
                ],
            ]),
            str_contains($body, 'ARRAY JOIN mapKeys(Attributes)') => mcpMetricRows([
                ['Key' => 'http.route', 'Values' => ['/checkout', '/cart']],
            ]),
            str_contains($body, 'AS Total') => mcpMetricRows([['Total' => 2]]),
            // One-minute buckets: 10:59 is the baseline, 11:00 the second bucket.
            str_contains($body, 'AS Start') => mcpMetricRows([
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 100, 'Start' => $at('10:00:00')],
                ['S' => '1', 'Grp' => '/checkout', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 280, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('10:59:00'), 'At' => $at('10:59:30'), 'V' => 10, 'Start' => $at('10:00:00')],
                ['S' => '2', 'Grp' => '/cart', 'Bucket' => $at('11:00:00'), 'At' => $at('11:00:30'), 'V' => 70, 'Start' => $at('10:00:00')],
            ]),
            str_contains($body, 'SELECT 1 FROM (') => mcpMetricRows([['1' => 1]]),
            default => '',
        });
    });
}

test('list-projects reports whether a project has metrics', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee('"hasMetrics":true');
});

test('list-metrics returns the catalog, saying how each metric is read', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(ListMetricsTool::class)
        ->assertOk()
        ->assertSee('"name":"http.server.requests"')
        ->assertSee('cumulative counter: queried as a per-second rate')
        ->assertSee('"name":"process.memory.usage"')
        ->assertSee('gauge: a sampled level')
        // The discovery window is a day, not an hour.
        ->assertSee('"from":"2026-09-24T12:00:00+00:00"')
        ->assertDontSee('"attributes"');
});

test('list-metrics filters by service and, given a metric, lists its attributes', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(ListMetricsTool::class, ['service' => 'checkout', 'metric' => 'http.server.requests'])
        ->assertOk()
        ->assertSee('"count":1')
        ->assertDontSee('process.memory.usage')
        ->assertSee('"key":"http.route","values":["/checkout","/cart"]');
});

test('query-metric returns a compact rate series with per-series summaries', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, [
            'metric' => 'http.server.requests',
            'group_by' => 'http.route',
            'from' => '2026-09-25T10:59:00Z',
            'to' => '2026-09-25T11:01:00Z',
        ])
        ->assertOk()
        ->assertSee('"kind":"rate"')
        ->assertSee('"unit":"{request}/s"')
        ->assertSee('"intervalSeconds":60')
        ->assertSee('"buckets":["2026-09-25T10:59:00Z","2026-09-25T11:00:00Z"')
        // (280 - 100) and (70 - 10) over the 60 s between points, at 11:00 only.
        ->assertSee('"label":"/checkout","stat":"rate","summary":{"min":3')
        ->assertSee('"last":3')
        ->assertSee('"label":"/cart","stat":"rate","summary":{"min":1')
        ->assertSee('"points":1')
        ->assertSee('"notes":[]');
});

test('query-metric reads only the team\'s own projects and binds every filter', function () {
    [$user, , $project] = mcpMetricsTeam();
    Project::factory()->create();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, [
            'metric' => 'http.server.requests',
            'service' => 'checkout',
            'where' => ['http.route' => "/cart' OR 1=1 --"],
        ])
        ->assertOk();

    Http::assertSent(function (Request $request) use ($project) {
        if (! str_contains($request->body(), 'AS Start')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($query['param_projectIds'])->toBe("['".$project->id."']")
            ->and($query['param_service'])->toBe('checkout')
            ->and($query['param_whereKey0'])->toBe('http.route')
            ->and($query['param_whereValue0'])->toBe("/cart' OR 1=1 --")
            ->and($request->body())->not->toContain('OR 1=1');

        return true;
    });
});

test('query-metric refuses a project the team does not have, and asks no question of the store', function () {
    [$user] = mcpMetricsTeam();
    $foreign = Project::factory()->create(['slug' => 'foreign']);
    Http::fake();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, ['metric' => 'http.server.requests', 'project' => $foreign->slug])
        ->assertHasErrors(["Team 'acme' has no project with slug 'foreign'."]);

    BilisServer::actingAs($user)
        ->tool(ListMetricsTool::class, ['project' => $foreign->slug])
        ->assertHasErrors(["Team 'acme' has no project with slug 'foreign'."]);

    Http::assertNothingSent();
});

test('a metric window is clamped to thirty days', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, [
            'metric' => 'process.memory.usage',
            'from' => '2025-01-01T00:00:00Z',
            'to' => '2026-09-25T12:00:00Z',
        ])
        ->assertOk()
        ->assertSee('"from":"2026-08-26T12:00:00+00:00"')
        ->assertSee('"agg":"avg"');

    BilisServer::actingAs($user)
        ->tool(ListMetricsTool::class, ['from' => '2025-01-01T00:00:00Z'])
        ->assertOk()
        ->assertSee('"from":"2026-08-26T12:00:00+00:00"');

    Http::assertNotSent(fn (Request $request) => str_contains((string) (clickHouseQuery($request)['param_from'] ?? ''), '2025-'));
});

test('query-metric names list-metrics when the metric does not exist', function () {
    [$user] = mcpMetricsTeam();
    fakeMcpMetricsClickHouse();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, ['metric' => 'http.server.duration'])
        ->assertHasErrors(["No metric named 'http.server.duration'", 'Call list-metrics']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'AS Start'));
});

test('query-metric requires a metric and caps the attribute filters', function () {
    [$user] = mcpMetricsTeam();
    Http::fake();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, [])
        ->assertHasErrors();

    BilisServer::actingAs($user)
        ->tool(QueryMetricTool::class, [
            'metric' => 'http.server.requests',
            'where' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6'],
        ])
        ->assertHasErrors(['At most 5 attribute filters']);

    Http::assertNothingSent();
});
