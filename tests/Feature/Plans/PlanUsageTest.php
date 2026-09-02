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

    // One statement per table, and nothing else.
    Http::assertSentCount(2);
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

    expect($second['events']['used'])->toBe($first['events']['used']);

    Http::assertSentCount(2);
});

it('never touches ClickHouse for a team with no projects', function () {
    Http::fake();

    $team = Team::factory()->create();

    $usage = app(PlanUsage::class)->forTeam($team, []);

    expect($usage['events']['used'])->toBe(0)
        ->and($usage['events']['unavailable'])->toBeFalse();

    Http::assertNothingSent();
});

it('reports an overloaded ClickHouse instead of inventing a zero, and does not cache it', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response('Too many simultaneous queries', 503));

    $service = app(PlanUsage::class);

    $usage = $service->forTeam($team, $ids);

    expect($usage['events']['unavailable'])->toBeTrue()
        ->and($usage['events']['used'])->toBe(0);

    // Not cached: the next visit must be able to recover.
    $service->forTeam($team, $ids);

    Http::assertSentCount(2);
});

it('rethrows a ClickHouse error that is not an overload', function () {
    [$team, $ids] = planTeam();

    Http::fake(fn () => Http::response('Code: 62. DB::Exception: Syntax error', 400));

    expect(fn () => app(PlanUsage::class)->forTeam($team, $ids))
        ->toThrow(ClickHouseException::class);
});
