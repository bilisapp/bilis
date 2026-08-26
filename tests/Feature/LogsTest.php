<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a team with one member and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function logTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

/**
 * A JSONEachRow response body containing a single log row.
 */
function logRowsResponse(string $body = 'boom'): string
{
    return json_encode([
        'ProjectId' => 1,
        'Timestamp' => '2026-08-26 10:00:00.000000000',
        'TraceId' => 'trace-1',
        'SpanId' => 'span-1',
        'SeverityText' => 'ERROR',
        'SeverityNumber' => 17,
        'ServiceName' => 'api',
        'Body' => $body,
        'ScopeName' => 'scope',
        'ScopeVersion' => '1.0',
        'ResourceAttributes' => ['host' => 'web-1'],
        'LogAttributes' => ['request_id' => 'abc'],
    ])."\n";
}

/**
 * Pull the query string of the last ClickHouse request as an array.
 *
 * @return array<string, string>
 */
function clickHouseQuery(Request $request): array
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

test('guests are redirected to the login page', function () {
    [, $team] = logTeam();

    $this->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('a team member can view the logs page', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('logs/Index')
            ->has('projects', 1)
            ->where('projects.0.slug', 'checkout')
            ->has('severityLevels', 6)
            ->where('filters.project', null)
            ->has('filters.from')
            ->has('filters.to')
            ->missing('logs')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('logs.unavailable', false)
                ->has('logs.rows', 1)
                ->where('logs.rows.0.body', 'boom')
                ->where('logs.rows.0.serviceName', 'api')
                ->where('logs.rows.0.logAttributes.request_id', 'abc'),
            ),
        );
});

test('a user who does not belong to the team is forbidden', function () {
    [, $team] = logTeam();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('filters are sent to clickhouse as bound query parameters', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    [$user, $team, $project] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'project' => 'checkout',
            'service' => 'api',
            'severity' => ['error'],
            'search' => 'timeout',
            'from' => '2026-08-26T09:00:00Z',
            'to' => '2026-08-26T10:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('logs.rows', 1),
        ));

    Http::assertSent(function (Request $request) use ($project) {
        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(UInt64)}')
            && str_contains($request->body(), 'ServiceName = {service:String}')
            && str_contains($request->body(), 'hasToken(Body, {search:String})')
            && str_contains($request->body(), 'ORDER BY Timestamp DESC LIMIT {rowLimit:UInt32}')
            && ! str_contains($request->body(), 'timeout"')
            && $query['param_projectIds'] === '['.$project->id.']'
            && $query['param_service'] === 'api'
            && $query['param_search'] === 'timeout'
            && $query['param_severityMin0'] === '17'
            && $query['param_severityMax0'] === '20'
            && $query['param_from'] === '2026-08-26 09:00:00.000000'
            && $query['param_to'] === '2026-08-26 10:00:00.000000'
            && $query['param_rowLimit'] === '100';
    });
});

test('a multi word search falls back to an escaped ilike match', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'search' => 'connection refused 100%',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('logs.rows'),
        ));

    Http::assertSent(function (Request $request) {
        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'Body ILIKE {search:String}')
            && $query['param_search'] === '%connection refused 100\%%';
    });
});

test('a project outside the team is never queried', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = logTeam();

    $otherProject = Project::factory()->create(['slug' => 'not-mine']);

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'project' => 'not-mine',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                ->has('logs.rows', 0)
                ->where('logs.unavailable', false),
        ));

    expect($otherProject->id)->toBeGreaterThan(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), ':8123'));
});

test('an overloaded clickhouse renders the page with an unavailable flag', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Code: 202. Too many simultaneous queries', 503),
    ]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                ->where('logs.unavailable', true)
                ->has('logs.rows', 0),
        ));
});

test('the tail endpoint returns rows newer than the given timestamp', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse('tailed'))]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->getJson(route('logs.tail', [
            'current_team' => $team->slug,
            'after' => '2026-08-26 09:59:00.000000',
        ]))
        ->assertOk()
        ->assertJsonPath('unavailable', false)
        ->assertJsonPath('rows.0.body', 'tailed')
        ->assertJsonPath('rows.0.severityNumber', 17);

    Http::assertSent(function (Request $request) {
        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'Timestamp > {after:DateTime64(9)}')
            && ! str_contains($request->body(), '{to:DateTime64(9)}')
            && $query['param_after'] === '2026-08-26 09:59:00.000000';
    });
});

test('the tail endpoint is forbidden for non members', function () {
    [, $team] = logTeam();

    $this->actingAs(User::factory()->create())
        ->getJson(route('logs.tail', ['current_team' => $team->slug]))
        ->assertForbidden();
});

/**
 * A JSONEachRow response body for the histogram aggregate.
 */
function histogramRowsResponse(): string
{
    return collect([
        ['Bucket' => '2026-08-26 09:00:00.000000000', 'Level' => 2, 'Total' => 40],
        ['Bucket' => '2026-08-26 09:00:00.000000000', 'Level' => 4, 'Total' => 2],
        ['Bucket' => '2026-08-26 09:30:00.000000000', 'Level' => 4, 'Total' => 7],
    ])->map(fn (array $row): string => json_encode($row))->implode("\n")."\n";
}

test('the histogram is deferred, bucketed and filled across the window', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(histogramRowsResponse())]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'from' => '2026-08-26T09:00:00Z',
            'to' => '2026-08-26T10:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('histogram')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('histogram.unavailable', false)
                // A one hour window lands on 300 second buckets: 12 plus the
                // closing edge, every one present even where nothing was logged.
                ->where('histogram.intervalSeconds', 300)
                ->has('histogram.buckets', 13)
                ->where('histogram.total', 49)
                ->where('histogram.buckets.0.counts.info', 40)
                ->where('histogram.buckets.0.counts.error', 2)
                ->where('histogram.buckets.0.total', 42)
                ->where('histogram.buckets.1.total', 0)
                ->where('histogram.buckets.6.counts.error', 7),
            ),
        );
});

test('the histogram groups by severity with bound parameters only', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(histogramRowsResponse())]);

    [$user, $team, $project] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'service' => 'api',
            'search' => 'timeout',
            'from' => '2026-08-26T09:00:00Z',
            'to' => '2026-08-26T10:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('histogram.buckets'),
        ));

    Http::assertSent(function (Request $request) use ($project) {
        if (! str_contains($request->body(), 'GROUP BY Bucket, Level')) {
            return false;
        }

        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'toIntervalSecond({bucketSeconds:UInt32})')
            && str_contains($request->body(), 'ProjectId IN {projectIds:Array(UInt64)}')
            && str_contains($request->body(), 'ServiceName = {service:String}')
            && str_contains($request->body(), 'hasToken(Body, {search:String})')
            && ! str_contains($request->body(), 'timeout"')
            && $query['param_bucketSeconds'] === '300'
            && $query['param_projectIds'] === '['.$project->id.']'
            && $query['param_service'] === 'api';
    });
});

test('a wider window gets wider buckets', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'from' => '2026-08-19T09:00:00Z',
            'to' => '2026-08-26T09:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                // Seven days over a forty-eight bar target: six hour buckets,
                // aligned down to the bucket the window starts inside.
                ->where('histogram.intervalSeconds', 21600)
                ->where('histogram.total', 0)
                ->has('histogram.buckets', 29),
        ));
});

test('an overloaded clickhouse leaves the histogram flagged unavailable', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Too many simultaneous queries', 503),
    ]);

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                ->where('histogram.unavailable', true)
                ->where('histogram.total', 0),
        ));
});

test('a project outside the team is never counted', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    [$user, $team] = logTeam();

    Project::factory()->create(['slug' => 'not-mine']);

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'project' => 'not-mine',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                ->where('histogram.total', 0)
                ->where('histogram.unavailable', false),
        ));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), ':8123'));
});
