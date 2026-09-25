<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Plans\PlanUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A team with one project, and the project id list the controller would pass.
 *
 * @return array{0: Team, 1: list<string>}
 */
function planTeam(int $projects = 1): array
{
    $team = Team::factory()->create();

    $ids = [];

    foreach (range(1, max(0, $projects)) as $index) {
        if ($projects <= 0) {
            break;
        }

        $ids[] = (string) Project::factory()->forTeam($team)->create()->id;
    }

    return [$team, $ids];
}

it('counts projects and members from the app database', function () {
    [$team, $ids] = planTeam(2);
    $team->members()->attach(User::factory()->create(), ['role' => 'owner']);

    Http::fake(fn () => Http::response(json_encode(['LogsToday' => '0'])."\n"));

    $usage = app(PlanUsage::class)->forTeam($team, $ids);

    expect($usage['plan'])->toBe('free')
        ->and($usage['projects']['used'])->toBe(2)
        ->and($usage['projects']['limit'])->toBe((int) config('plans.free.projects_per_team'))
        ->and($usage['members']['used'])->toBe(1)
        ->and($usage['members']['limit'])->toBe((int) config('plans.free.members_per_team'))
        ->and($usage['retentionDays'])->toBe((int) config('legal.log_retention_days'))
        ->and($usage['requestsPerMinute'])->toBe((int) config('security.ingest_rate_limit'))
        ->and($usage['warnAtPercent'])->toBe((int) config('plans.warn_at_percent'));
});

it('adds today\'s logs and spans into one event count', function () {
    [$team, $ids] = planTeam();

    Http::fake(function (Request $request) {
        return str_contains($request->body(), 'otel_traces')
            ? Http::response(json_encode(['SpansToday' => '2500'])."\n")
            : Http::response(json_encode(['LogsToday' => '17500'])."\n");
    });

    $usage = app(PlanUsage::class)->forTeam($team, $ids);

    expect($usage['events']['logs'])->toBe(17_500)
        ->and($usage['events']['spans'])->toBe(2_500)
        ->and($usage['events']['used'])->toBe(20_000)
        ->and($usage['events']['limit'])->toBe((int) config('plans.free.events_per_day'))
        ->and($usage['events']['unavailable'])->toBeFalse()
        ->and($usage['events']['since'])->toBe(now()->utc()->startOfDay()->format('Y-m-d H:i:s.u'));
});

it('counts with a plain R4 range read over the resolved project ids', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response(json_encode(['LogsToday' => '1'])."\n"));

    app(PlanUsage::class)->forTeam($team, $ids);

    Http::assertSent(function (Request $request) use ($ids) {
        $body = clickHouseStatement($request);
        $query = clickHouseQuery($request);

        return str_contains($body, 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($body, 'Timestamp >= {from:DateTime64(9)}')
            && str_contains($body, 'Timestamp <= {to:DateTime64(9)}')
            // R4: no bucket expression anywhere near a range query.
            && ! str_contains($body, 'toStartOf')
            && $query['param_projectIds'] === "['".$ids[0]."']"
            && $query['param_from'] === now()->utc()->startOfDay()->format('Y-m-d H:i:s.u');
    });

    // One statement per event table, one for all five metric tables, and nothing else.
    Http::assertSentCount(3);
});

it('reads both tables', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response(json_encode(['LogsToday' => '1'])."\n"));

    app(PlanUsage::class)->forTeam($team, $ids);

    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'FROM otel_logs'));
    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'FROM otel_traces'));
});

it('caches the measured counts', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response(json_encode(['LogsToday' => '5', 'SpansToday' => '5'])."\n"));

    $service = app(PlanUsage::class);

    $first = $service->forTeam($team, $ids);
    $second = $service->forTeam($team, $ids);

    expect($second['events']['used'])->toBe($first['events']['used'])
        ->and($second['metricPoints']['used'])->toBe($first['metricPoints']['used']);

    Http::assertSentCount(3);
});

it('never touches ClickHouse for a team with no projects', function () {
    Http::fake();

    $team = Team::factory()->create();

    $usage = app(PlanUsage::class)->forTeam($team, []);

    expect($usage['events']['used'])->toBe(0)
        ->and($usage['events']['unavailable'])->toBeFalse()
        ->and($usage['metricPoints']['used'])->toBe(0)
        ->and($usage['metricPoints']['limit'])->toBe((int) config('plans.free.metric_points_per_day'))
        ->and($usage['metricPoints']['unavailable'])->toBeFalse();

    Http::assertNothingSent();
});

it('reports an overloaded ClickHouse instead of inventing a zero, and does not cache it', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response('Too many simultaneous queries', 503));

    $service = app(PlanUsage::class);

    $usage = $service->forTeam($team, $ids);

    expect($usage['events']['unavailable'])->toBeTrue()
        ->and($usage['events']['used'])->toBe(0)
        ->and($usage['metricPoints']['unavailable'])->toBeTrue()
        ->and($usage['metricPoints']['used'])->toBe(0);

    // Not cached: the next visit must be able to recover.
    $service->forTeam($team, $ids);

    Http::assertSentCount(4);
});

it('rethrows a ClickHouse error that is not an overload', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response('Code: 62. DB::Exception: Syntax error', 400));

    expect(fn () => app(PlanUsage::class)->forTeam($team, $ids))
        ->toThrow(ClickHouseException::class);
});

it('sums today\'s metric data points across all five metric tables in one statement', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn (Request $request) => str_contains($request->body(), 'otel_metrics_gauge')
        ? Http::response(json_encode(['MetricPointsToday' => '412345'])."\n")
        : Http::response(json_encode(['LogsToday' => '1'])."\n"));

    $usage = app(PlanUsage::class)->forTeam($team, $ids);

    expect($usage['metricPoints'])->toBe([
        'used' => 412_345,
        'limit' => (int) config('plans.free.metric_points_per_day'),
        'since' => now()->utc()->startOfDay()->format('Y-m-d H:i:s'),
        'unavailable' => false,
    ])
        // Never folded into events.
        ->and($usage['events']['used'])->toBe(1);

    $metricStatements = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->body(), 'otel_metrics_'));

    expect($metricStatements)->toHaveCount(1);

    /** @var Request $request */
    $request = $metricStatements->first();
    $body = clickHouseStatement($request);
    $query = clickHouseQuery($request);

    foreach (['gauge', 'sum', 'histogram', 'exponential_histogram', 'summary'] as $kind) {
        expect($body)->toContain('FROM otel_metrics_'.$kind.' WHERE');
    }

    expect($body)
        ->toStartWith('SELECT sum(c) AS MetricPointsToday FROM (')
        ->and(substr_count($body, 'UNION ALL'))->toBe(4)
        ->and(substr_count($body, 'ProjectId IN {projectIds:Array(String)}'))->toBe(5)
        ->and(substr_count($body, "TimeUnix >= {from:DateTime('UTC')}"))->toBe(5)
        ->and(substr_count($body, "TimeUnix <= {to:DateTime('UTC')}"))->toBe(5)
        // Bound, never interpolated; and R4: no bucket expression.
        ->and($body)->not->toContain($ids[0])
        ->and($body)->not->toContain('toStartOf')
        ->and($query['param_projectIds'])->toBe("['".$ids[0]."']")
        ->and($query['param_from'])->toBe(now()->utc()->startOfDay()->format('Y-m-d H:i:s'))
        ->and($query['param_to'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('caches the metric point count under its own per-day key', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response(json_encode(['MetricPointsToday' => '9'])."\n"));

    $service = app(PlanUsage::class);
    $service->forTeam($team, $ids);

    expect(cache()->get('plans.metric-points.'.now()->utc()->format('Y-m-d').'.'.sha1(implode(',', $ids))))->toBe(9);

    $second = $service->forTeam($team, $ids);

    expect($second['metricPoints']['used'])->toBe(9);

    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'otel_metrics_gauge'));
    Http::assertSentCount(3);
});

it('marks only the metric meter unavailable when its read is overloaded', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn (Request $request) => str_contains($request->body(), 'otel_metrics_gauge')
        ? Http::response('Too many simultaneous queries', 503)
        : Http::response(json_encode(['LogsToday' => '3', 'SpansToday' => '4'])."\n"));

    $service = app(PlanUsage::class);
    $usage = $service->forTeam($team, $ids);

    expect($usage['metricPoints']['unavailable'])->toBeTrue()
        ->and($usage['metricPoints']['used'])->toBe(0)
        ->and($usage['events']['unavailable'])->toBeFalse()
        ->and($usage['events']['used'])->toBe(7);

    // Events came from cache; the metric read was not cached and is retried.
    $service->forTeam($team, $ids);

    $metricReads = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->body(), 'otel_metrics_gauge'));

    expect($metricReads)->toHaveCount(2);
    Http::assertSentCount(4);
});

it('rethrows a metric read error that is not an overload', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn (Request $request) => str_contains($request->body(), 'otel_metrics_gauge')
        ? Http::response('Code: 60. DB::Exception: Unknown table', 404)
        : Http::response(json_encode(['LogsToday' => '1'])."\n"));

    expect(fn () => app(PlanUsage::class)->forTeam($team, $ids))
        ->toThrow(ClickHouseException::class);
});
