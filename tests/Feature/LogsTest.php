<?php

use App\Enums\TeamRole;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
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
        'ProjectId' => '1',
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

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($request->body(), 'ServiceName = {service:String}')
            // SCHEMA.md R4: a plain range on the raw Timestamp, which is the
            // second sort key column. No bucket expression anywhere.
            && str_contains($request->body(), 'Timestamp >= {from:DateTime64(9)}')
            && str_contains($request->body(), 'Timestamp <= {to:DateTime64(9)}')
            && ! str_contains($request->body(), 'toStartOfFiveMinutes')
            // R5, <26.2 branch: the expression must be the indexed one.
            && str_contains($request->body(), 'hasAnyTokens(lower(Body), [lower({search:String})])')
            && str_contains($request->body(), 'ORDER BY Timestamp DESC LIMIT {rowLimit:UInt32}')
            && ! str_contains($request->body(), 'timeout"')
            && $query['param_projectIds'] === "['".$project->id."']"
            && $query['param_service'] === 'api'
            && $query['param_search'] === 'timeout'
            && $query['param_severityMin0'] === '17'
            && $query['param_severityMax0'] === '20'
            && $query['param_from'] === '2026-08-26 09:00:00.000000'
            && $query['param_to'] === '2026-08-26 10:00:00.000000'
            && $query['param_rowLimit'] === '100';
    });
});

test('a multi word search falls back to an escaped substring match on the indexed expression', function () {
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

        return str_contains($request->body(), 'lower(Body) LIKE lower({search:String})')
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

    // The onboarding existence check still runs, but nothing goes looking for
    // rows: the foreign slug resolves to an empty id list and short-circuits.
    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'ORDER BY Timestamp DESC'));
    Http::assertNotSent(fn (Request $request) => str_contains(
        clickHouseQuery($request)['param_projectIds'] ?? '',
        (string) $otherProject->id,
    ));
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
            && ! str_contains($request->body(), 'toStartOfFiveMinutes')
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
            && str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($request->body(), 'ServiceName = {service:String}')
            && str_contains($request->body(), 'hasAnyTokens(lower(Body), [lower({search:String})])')
            && ! str_contains($request->body(), 'timeout"')
            && $query['param_bucketSeconds'] === '300'
            && $query['param_projectIds'] === "['".$project->id."']"
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

    $otherProject = Project::factory()->create(['slug' => 'not-mine']);

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

    // Only the onboarding existence check may talk to ClickHouse here: no
    // aggregate is run, and the foreign project id is never bound.
    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'GROUP BY Bucket, Level'));
    Http::assertNotSent(fn (Request $request) => str_contains(
        clickHouseQuery($request)['param_projectIds'] ?? '',
        (string) $otherProject->id,
    ));
});

test('the service picker is filled from the services seen in scope', function () {
    Http::fake(function (Request $request) {
        if (str_contains((string) $request->body(), 'SELECT DISTINCT ServiceName')) {
            return Http::response(
                json_encode(['ServiceName' => 'api'])."\n"
                .json_encode(['ServiceName' => 'worker'])."\n"
            );
        }

        return Http::response(logRowsResponse());
    });

    [$user, $team, $project] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', [
            'current_team' => $team->slug,
            'service' => 'api',
            'severity' => ['error'],
            'search' => 'timeout',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('services')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('services', 2)
                ->where('services.0', 'api')
                ->where('services.1', 'worker'),
            ),
        );

    Http::assertSent(function (Request $request) use ($project) {
        if (! str_contains((string) $request->body(), 'SELECT DISTINCT ServiceName')) {
            return false;
        }

        $query = clickHouseQuery($request);

        return str_contains((string) $request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains((string) $request->body(), 'ORDER BY ServiceName ASC')
            // The list is what you can switch *to*, so the active service,
            // severity and search filters must not narrow it.
            && ! str_contains((string) $request->body(), 'ServiceName = {service:String}')
            && ! str_contains((string) $request->body(), 'SeverityNumber')
            && ! str_contains((string) $request->body(), 'Body')
            && $query['param_projectIds'] === "['".$project->id."']";
    });
});

test('the service picker degrades to an empty list when clickhouse is overloaded', function () {
    Http::fake(function (Request $request) {
        if (str_contains((string) $request->body(), 'SELECT DISTINCT ServiceName')) {
            return Http::response('Too many simultaneous queries', 503);
        }

        return Http::response(logRowsResponse());
    });

    [$user, $team] = logTeam();

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('services', 0),
        ));
});

test('the older endpoint returns the page behind the cursor as json', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse('older'))]);

    [$user, $team, $project] = logTeam();

    $this->actingAs($user)
        ->getJson(route('logs.older', [
            'current_team' => $team->slug,
            'project' => 'checkout',
            'service' => 'api',
            'cursor' => '2026-08-26T09:30:00Z',
            'from' => '2026-08-26T09:00:00Z',
            'to' => '2026-08-26T10:00:00Z',
        ]))
        ->assertOk()
        ->assertJsonPath('unavailable', false)
        ->assertJsonPath('rows.0.body', 'older');

    Http::assertSent(function (Request $request) use ($project) {
        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'Timestamp < {cursor:DateTime64(9)}')
            && str_contains($request->body(), 'ORDER BY Timestamp DESC')
            && str_contains($request->body(), 'ServiceName = {service:String}')
            && $query['param_cursor'] === '2026-08-26 09:30:00.000000'
            && $query['param_projectIds'] === "['".$project->id."']";
    });
});

test('the older endpoint is forbidden for non members', function () {
    [, $team] = logTeam();

    $this->actingAs(User::factory()->create())
        ->getJson(route('logs.older', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('the log viewer is told which service has a codebase behind it', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    config()->set('autofix.enabled', true);

    [$user, $team, $project] = logTeam();

    $installation = GitHubInstallation::factory()->forTeam($team)->create();

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->forServices(['api'])
        ->create(['repo_full_name' => 'acme/api']);

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->forServices(['*'])
        ->create(['repo_full_name' => 'acme/checkout']);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('logs/Index')
            ->where('autofix.enabled', true)
            ->where('autofix.connected', true)
            // Keyed by the project id the log rows carry, so a row resolves
            // its own repository without a second request.
            ->where('autofix.projects.'.$project->id.'.services.api', 'acme/api')
            ->where('autofix.projects.'.$project->id.'.catchAll', 'acme/checkout')
            ->where('autofix.projects.'.$project->id.'.slug', 'checkout'),
        );
});

test('a repository that has not opted into autofix is not offered', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    config()->set('autofix.enabled', true);

    [$user, $team, $project] = logTeam();

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation(GitHubInstallation::factory()->forTeam($team)->create())
        ->create(['repo_full_name' => 'acme/checkout', 'autofix_enabled' => false]);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('autofix.connected', false)
            ->where('autofix.projects.'.$project->id.'.catchAll', null),
        );
});

test('the deployment switch takes the whole offer off the page', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response(logRowsResponse())]);

    config()->set('autofix.enabled', false);

    [$user, $team, $project] = logTeam();

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation(GitHubInstallation::factory()->forTeam($team)->create())
        ->autofixEnabled()
        ->create(['repo_full_name' => 'acme/checkout']);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('autofix.enabled', false)
            ->where('autofix.connected', false)
            ->where('autofix.credentials', []),
        );
});

/*
 * The log row must ASK for the trace, not navigate to it: the panel exists so a
 * reader keeps their place in the stream, and a row that carried its own <Link>
 * would take that away again. There is no JS test runner in this project, so
 * this is a source pin in the same spirit as the DDL assertions in
 * ClickHouseMigrateCommandTest — it fails loudly if the row is ever wired back
 * to a direct link.
 */
test('a log row emits its trace instead of navigating to it', function () {
    $row = (string) file_get_contents(resource_path('js/components/LogRowActions.vue'));

    expect($row)
        // The button announces itself as a preview and reports upward.
        ->toContain("emit('trace', entry.traceId)")
        ->toContain('data-test="log-row-trace"')
        // No route helper, no <Link>: the page owns where this goes.
        ->not->toContain('@/routes/traces')
        ->and($row)->not->toContain('traceShow');

    $page = (string) file_get_contents(resource_path('js/pages/logs/Index.vue'));

    // And the page is actually listening, or the emit goes nowhere.
    expect($page)
        ->toContain('@trace="onRowTrace(entry)"')
        ->toContain('<TracePanel');
});
