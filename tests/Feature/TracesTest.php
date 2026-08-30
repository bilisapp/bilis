<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team with one member and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function traceTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

/**
 * A JSONEachRow body holding one aggregated trace summary row.
 *
 * The keys are the query's output aliases, which deliberately differ from the
 * column names: aliasing an aggregate to its own column makes a HAVING over it
 * resolve recursively and fail. See SCHEMA.md R11.
 *
 * @param  array<string, mixed>  $overrides
 */
function traceSummaryResponse(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'TraceId' => str_repeat('a', 32),
        'TraceRootName' => 'POST /checkout',
        'TraceRootService' => 'checkout',
        'Started' => '2026-08-30 09:14:02.184000000',
        'Ended' => '2026-08-30 09:14:02.436000000',
        'TraceSpanCount' => 14,
        'TraceErrorCount' => 2,
    ], $overrides));
}

/**
 * A JSONEachRow body holding one span row.
 *
 * @param  array<string, mixed>  $overrides
 */
function spanResponse(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'Timestamp' => '2026-08-30 09:14:02.184000000',
        'TraceId' => str_repeat('a', 32),
        'SpanId' => str_repeat('b', 16),
        'ParentSpanId' => '',
        'SpanName' => 'POST /checkout',
        'SpanKind' => 'Server',
        'ServiceName' => 'checkout',
        'Duration' => 252000000,
        'StatusCode' => 'Error',
        'StatusMessage' => 'checkout failed',
        'SpanAttributes' => ['http.method' => 'POST'],
        'Events.Timestamp' => [],
        'Events.Name' => [],
        'Events.Attributes' => [],
    ], $overrides));
}

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
});

test('the trace list renders the team traces', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('traces/Index')
            ->where('hasTraces', true)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('traces.rows', 1)
                ->where('traces.rows.0.rootName', 'POST /checkout')
                ->where('traces.rows.0.spanCount', 14)
                ->where('traces.rows.0.errorCount', 2)
                // 252ms between Started and Ended.
                ->where('traces.rows.0.durationMs', 252)
                ->etc()
            )
        );
});

/*
 * The read half of SCHEMA.md R11. trace_summary is an AggregatingMergeTree, so
 * a trace's rows collapse only when parts merge; a reader without this GROUP BY
 * shows the same trace once per insert block with partial counts.
 */
test('the trace list re-aggregates the summary table on read', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('traces.rows'),
        ));

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        // hasAnyTraces reads the same table, so match the list query itself.
        if (! str_contains($body, 'sum(SpanCount)')) {
            return false;
        }

        expect($body)->toContain('GROUP BY ProjectId, TraceId')
            ->toContain('sum(SpanCount)')
            ->toContain('sum(ErrorCount)')
            ->toContain('max(RootName)    AS TraceRootName')
            ->toContain('min(Start)');

        return true;
    });
});

test('trace queries are scoped to the team and bound as parameters', function () {
    [$user, $team, $project] = traceTeam();

    // A project on another team must never appear in the predicate.
    $otherProject = Project::factory()->create();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('traces.rows'),
        ));

    Http::assertSent(function (Request $request) use ($project, $otherProject) {
        $query = clickHouseQuery($request);

        if (! str_contains($request->body(), 'sum(SpanCount)')) {
            return false;
        }

        expect($request->body())->toContain('ProjectId IN {projectIds:Array(String)}')
            ->and($query['param_projectIds'] ?? '')->toBe("['".$project->id."']")
            ->and($query['param_projectIds'] ?? '')->not->toContain((string) $otherProject->id);

        return true;
    });
});

test('the errors-only and duration filters are applied after aggregation', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', [
            'current_team' => $team->slug,
            'errors' => '1',
            'min_duration' => 250,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload->has('traces.rows'),
        ));

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        if (! str_contains($body, 'sum(SpanCount)')) {
            return false;
        }

        // Both are sums or extremes across a trace's rows, so neither means
        // anything until the GROUP BY has run.
        expect($body)->toContain('HAVING')
            ->toContain('sum(ErrorCount) > 0')
            ->toContain("dateDiff('millisecond', min(Start), max(End)) >= {minDuration:UInt32}");

        return true;
    });
});

test('the waterfall bounds its span query to the given timestamp', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(spanResponse())]);

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('traces/Show')
            ->has('spans', 1)
            ->where('spans.0.name', 'POST /checkout')
            ->where('spans.0.statusCode', 'Error')
            // Nanoseconds on the wire, milliseconds in the UI.
            ->where('spans.0.durationMs', 252)
            ->where('spans.0.depth', 0)
            ->etc()
        );

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        if (! str_contains($body, 'FROM otel_traces')) {
            return false;
        }

        /*
         * The whole reason the timestamp is in the URL: the sort key leads with
         * (ProjectId, Timestamp), so without a range this is a scan of the
         * retention window rather than a seek.
         */
        expect($body)->toContain('Timestamp >= {from:DateTime64(9)}')
            ->toContain('Timestamp <= {to:DateTime64(9)}')
            ->toContain('TraceId = {traceId:String}');

        return true;
    });
});

test('a trace id with no timestamp is located through the summary table first', function () {
    [$user, $team] = traceTeam();

    $bodies = [];

    Http::fake(function (Request $request) use (&$bodies) {
        $bodies[] = $request->body();

        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? traceSummaryResponse()
                : spanResponse(),
        );
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('spans', 1)->etc());

    // Summary first, and only then the spans it bounded.
    expect($bodies[0])->toContain('FROM trace_summary')
        ->and($bodies[0])->toContain('sum(SpanCount)')
        ->and($bodies[1])->toContain('FROM otel_traces');
});

/*
 * Summaries live 90 days and spans 30, so a trace can legitimately be known and
 * unopenable. The row has to survive that, and say why.
 */
test('a trace whose spans have expired still renders its summary', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? traceSummaryResponse()
                : '',
        );
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.spanCount', 14)
            ->has('spans', 0)
            ->where('unavailable', false)
            ->etc()
        );
});

test('an unknown trace id renders an empty summary rather than failing', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('f', 32),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary', null)
            ->has('spans', 0)
            ->etc()
        );
});

test('a malformed trace id is a 404, never a query', function () {
    [$user, $team] = traceTeam();

    Http::fake();

    $this->actingAs($user)
        ->get("/{$team->slug}/traces/not-a-trace-id")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('an overloaded clickhouse still renders the trace list', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(
            fn (Assert $reload) => $reload
                ->where('traces.unavailable', true)
                ->has('traces.rows', 0)
                ->etc()
        ));
});

test('the traces page requires membership of the team', function () {
    [, $team] = traceTeam();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('the traces page requires authentication', function () {
    [, $team] = traceTeam();

    $this->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

/*
 * `ts` is a hint from a link, not a fact: it can be stale, hand-edited, or
 * written in the reader's timezone. A wrong one lands the span query on an empty
 * window, which looks exactly like expired spans — so an empty result is retried
 * against the time the summary itself reports before that conclusion is drawn.
 */
test('a stale timestamp falls back to the summary window instead of claiming the spans expired', function () {
    [$user, $team] = traceTeam();

    $spanQueries = 0;

    Http::fake(function (Request $request) use (&$spanQueries) {
        if (str_contains($request->body(), 'FROM trace_summary')) {
            return Http::response(traceSummaryResponse());
        }

        if (str_contains($request->body(), 'FROM otel_traces')) {
            $spanQueries++;

            // The window the bad `ts` produced finds nothing; the summary's own
            // window finds the trace.
            return Http::response($spanQueries === 1 ? '' : spanResponse());
        }

        return Http::response('');
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            // Two hours off — the shape a local-time timestamp would have.
            'ts' => '2026-08-30T11:14:02Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('spans', 1)
            ->etc()
        );

    // Once for the bad window, once for the summary's own.
    expect($spanQueries)->toBeGreaterThanOrEqual(2);
});

test('a timestamp that finds spans is not queried twice', function () {
    [$user, $team] = traceTeam();

    $spanQueries = 0;

    Http::fake(function (Request $request) use (&$spanQueries) {
        if (str_contains($request->body(), 'FROM trace_summary')) {
            return Http::response(traceSummaryResponse());
        }

        if (str_contains($request->body(), 'FROM otel_traces')) {
            $spanQueries++;

            return Http::response(spanResponse());
        }

        return Http::response('');
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk();

    // One for the spans, one for the root resource — never a retry.
    expect($spanQueries)->toBe(2);
});

/*
 * The log viewer's trace preview. It shares TracesController::resolve() with the
 * page, so the tests here are about the endpoint's own contract — shape, scoping
 * and failure modes — rather than re-proving the `ts` fallback the page covers.
 */
test('the panel endpoint returns the trace as json', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? traceSummaryResponse()
                : spanResponse(),
        );
    });

    $this->actingAs($user)
        ->getJson(route('traces.panel', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk()
        ->assertJsonPath('traceId', str_repeat('a', 32))
        ->assertJsonPath('summary.rootName', 'POST /checkout')
        ->assertJsonPath('summary.spanCount', 14)
        ->assertJsonPath('unavailable', false)
        ->assertJsonPath('spans.0.name', 'POST /checkout')
        // Flattened server-side, same as the page.
        ->assertJsonPath('spans.0.depth', 0)
        // The panel is a peek: no resource map, no span limit.
        ->assertJsonMissingPath('resource')
        ->assertJsonMissingPath('spanLimit');
});

test('the panel endpoint is scoped to the team', function () {
    [$user, $team, $project] = traceTeam();

    $otherProject = Project::factory()->create();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->getJson(route('traces.panel', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertOk();

    Http::assertSent(function (Request $request) use ($project, $otherProject) {
        $query = clickHouseQuery($request);

        expect($query['param_projectIds'] ?? '')->toBe("['".$project->id."']")
            ->and($query['param_projectIds'] ?? '')->not->toContain((string) $otherProject->id);

        return true;
    });
});

test('the panel endpoint 404s on a malformed trace id without querying', function () {
    [$user, $team] = traceTeam();

    Http::fake();

    $this->actingAs($user)
        ->getJson("/{$team->slug}/traces/not-a-trace-id/panel")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('the panel endpoint reports an overloaded clickhouse rather than failing', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($user)
        ->getJson(route('traces.panel', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertOk()
        ->assertJsonPath('unavailable', true)
        ->assertJsonPath('summary', null)
        ->assertJsonPath('spans', []);
});

/*
 * The panel reaches the shared resolver, so a stale `ts` self-heals here too —
 * this is the assertion that would fail if the logic were ever copied instead of
 * shared.
 */
test('the panel endpoint recovers from a stale timestamp', function () {
    [$user, $team] = traceTeam();

    $spanQueries = 0;

    Http::fake(function (Request $request) use (&$spanQueries) {
        if (str_contains($request->body(), 'FROM trace_summary')) {
            return Http::response(traceSummaryResponse());
        }

        $spanQueries++;

        return Http::response($spanQueries === 1 ? '' : spanResponse());
    });

    $this->actingAs($user)
        ->getJson(route('traces.panel', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            // Two hours off, the shape a local-time timestamp would have.
            'ts' => '2026-08-30T11:14:02Z',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'spans');

    expect($spanQueries)->toBeGreaterThanOrEqual(2);
});

test('the panel endpoint requires membership of the team', function () {
    [, $team] = traceTeam();

    $this->actingAs(User::factory()->create())
        ->getJson(route('traces.panel', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertForbidden();
});

/*
 * The two tabs. "What happened to this request" and "which service is slow" are
 * different questions, and they were competing for the same screen — a chart
 * that grows a row per service pushed the traces themselves out of view.
 */
test('service latency is its own page and no longer rides on the trace list', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('traces/Index')
            ->missing('serviceLatency')
            ->etc()
        );
});

test('the service latency page renders per-service quantiles', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'quantile(0.95)')) {
            return Http::response((string) json_encode([
                'ServiceName' => 'checkout',
                'Spans' => 400,
                'P95' => 252000000,
                'P99' => 512000000,
                'Errors' => 8,
            ]));
        }

        return Http::response(traceSummaryResponse());
    });

    $this->actingAs($user)
        ->get(route('traces.latency', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('traces/Latency')
            ->where('hasTraces', true)
            // The list is the other tab's job; this page never asks for it.
            ->missing('traces')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('serviceLatency.rows', 1)
                ->where('serviceLatency.rows.0.serviceName', 'checkout')
                ->where('serviceLatency.rows.0.p95Ms', 252)
                ->where('serviceLatency.rows.0.p99Ms', 512)
                ->where('serviceLatency.rows.0.errorRate', 0.02)
                ->etc()
            )
        );
});

test('the service latency page requires membership of the team', function () {
    [, $team] = traceTeam();

    $this->actingAs(User::factory()->create())
        ->get(route('traces.latency', ['current_team' => $team->slug]))
        ->assertForbidden();
});

/*
 * The live poll. A trace that arrives after the page loaded is by definition
 * past the window's upper bound, so the tail query drops that bound entirely —
 * keeping it would guarantee an empty answer forever.
 */
test('the tail endpoint reads forward from the cursor with no upper bound', function () {
    [$user, $team, $project] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->getJson(route('traces.tail', [
            'current_team' => $team->slug,
            'after' => '2026-08-30 09:14:02.184000000',
        ]))
        ->assertOk()
        ->assertJsonPath('rows.0.traceId', str_repeat('a', 32))
        ->assertJsonPath('rows.0.spanCount', 14);

    Http::assertSent(function (Request $request) use ($project) {
        $body = $request->body();

        if (! str_contains($body, 'sum(SpanCount)')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($body)->toContain('Start > {after:DateTime64(9)}')
            // The bound the list carries, and the one the tail must not.
            ->not->toContain('Start <= {to:DateTime64(9)}')
            // R11 applies just as much to a poll: a trace this fresh is exactly
            // the one whose rows have not been merged yet.
            ->toContain('GROUP BY ProjectId, TraceId')
            ->and($query['param_projectIds'] ?? '')->toBe("['".$project->id."']");

        // The poll re-reads the last few seconds so a still-arriving trace's
        // span count can settle; the client keys rows by trace id.
        expect($query['param_after'] ?? '')->toBe('2026-08-30 09:13:52.184000');

        return true;
    });
});

test('the tail endpoint keeps the list filters', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->getJson(route('traces.tail', [
            'current_team' => $team->slug,
            'errors' => '1',
            'min_duration' => 250,
            'after' => '2026-08-30 09:14:02.184000000',
        ]))
        ->assertOk();

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        if (! str_contains($body, 'sum(SpanCount)')) {
            return false;
        }

        expect($body)->toContain('HAVING')
            ->toContain('sum(ErrorCount) > 0')
            ->toContain("dateDiff('millisecond', min(Start), max(End)) >= {minDuration:UInt32}");

        return true;
    });
});

test('an overloaded clickhouse leaves the poll unavailable rather than failing', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($user)
        ->getJson(route('traces.tail', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertJsonPath('unavailable', true)
        ->assertJsonPath('rows', []);
});

test('the tail endpoint requires membership of the team', function () {
    [, $team] = traceTeam();

    $this->actingAs(User::factory()->create())
        ->getJson(route('traces.tail', ['current_team' => $team->slug]))
        ->assertForbidden();
});
