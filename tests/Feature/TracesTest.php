<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
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
 * The read half of SCHEMA.md R11, and R13 beside it. trace_summary is an
 * AggregatingMergeTree, so a trace's rows collapse only when parts merge; a
 * reader without this GROUP BY shows the same trace once per insert block with
 * partial counts. And the window is decided on the whole trace's min(Start) in
 * HAVING, never on one block's Start in WHERE: a boundary that falls between two
 * of a trace's blocks would otherwise aggregate the late block alone. The
 * candidates come from trace_index, which is keyed by the hour, so the read is
 * bounded by the window rather than by the project's history.
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
            ->toContain('min(Start)')
            // R13: candidates by the hour, membership on the exact aggregate.
            ->toContain('FROM trace_index')
            ->toContain('Hour >= toStartOfHour({candidateFrom:DateTime64(9)})')
            ->toContain('HAVING min(Start) >= {from:DateTime64(9)} AND min(Start) <= {to:DateTime64(9)}')
            ->not->toContain('WHERE Start >=')
            ->not->toContain('AND Start >= {from:DateTime64(9)}');

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

test('the waterfall bounds its span query to the window the summary reports', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
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

        // The waterfall query, not the root-resource lookup beside it.
        if (!str_contains($body, 'SpanAttributes')) {
            return false;
        }

        /*
         * The sort key leads with (ProjectId, Timestamp), so without a range
         * this is a scan of the retention window rather than a seek. The range
         * is the trace's own, from its summary, with a second of slack either
         * side — the `ts` in the URL is a hint and does not narrow it.
         */
        $query = clickHouseQuery($request);

        expect($body)->toContain('Timestamp >= {from:DateTime64(9)}')
            ->toContain('Timestamp <= {to:DateTime64(9)}')
            ->toContain('TraceId = {traceId:String}')
            // Siblings that started on the same nanosecond come back in one
            // order on every read.
            ->toContain('ORDER BY Timestamp ASC, SpanId ASC')
            ->and($query['param_from'] ?? '')->toBe('2026-08-30 09:14:01.184000')
            ->and($query['param_to'] ?? '')->toBe('2026-08-30 09:14:03.436000');

        return true;
    });
});

/*
 * The reason the window comes from the summary and not from the `ts`: the
 * bracket around a `ts` reached one minute back and five forward, so a log line
 * a minute into a trace, or any trace longer than five minutes — a queue job,
 * an agent session — came back as a rootless fragment with no fallback, because
 * the fragment was not empty.
 */
test('a trace longer than the timestamp bracket is read whole', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? traceSummaryResponse(['Ended' => '2026-08-30 09:34:02.436000000'])
                : spanResponse(),
        );
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            // From a log line eighteen minutes into the trace.
            'ts' => '2026-08-30T09:32:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->has('spans', 1)
            ->where('truncated', false)
            ->etc()
        );

    Http::assertSent(function (Request $request) {
        if (!str_contains($request->body(), 'SpanAttributes')) {
            return false;
        }

        $query = clickHouseQuery($request);

        // Twenty minutes, root to last span end, plus a second either side.
        expect($query['param_from'] ?? '')->toBe('2026-08-30 09:14:01.184000')
            ->and($query['param_to'] ?? '')->toBe('2026-08-30 09:34:03.436000');

        return true;
    });
});

test('a trace longer than the window cap is cut at the cap and says so', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? traceSummaryResponse(['Ended' => '2026-08-30 16:14:02.436000000'])
                : spanResponse(),
        );
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->has('spans', 1)
            // The window was cut, so the tree is incomplete and the page must
            // say so the same way it does for a span-count cut.
            ->where('truncated', true)
            ->etc()
        );

    Http::assertSent(function (Request $request) {
        if (!str_contains($request->body(), 'SpanAttributes')) {
            return false;
        }

        $query = clickHouseQuery($request);

        // Seven hours asked for, six granted, from the trace's start.
        expect($query['param_from'] ?? '')->toBe('2026-08-30 09:14:01.184000')
            ->and($query['param_to'] ?? '')->toBe('2026-08-30 15:14:01.184000');

        return true;
    });
});

/*
 * When there is no summary to trust — none stored, or storage too busy to say —
 * the `ts` is all there is, and it is bracketed the old way.
 */
test('without a summary the timestamp bracket bounds the span query', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        return Http::response(
            str_contains($request->body(), 'FROM trace_summary')
                ? ''
                : spanResponse(),
        );
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page->has('spans', 1)->etc());

    Http::assertSent(function (Request $request) {
        if (!str_contains($request->body(), 'SpanAttributes')) {
            return false;
        }

        $query = clickHouseQuery($request);

        // One minute back, five forward.
        expect($query['param_from'] ?? '')->toBe('2026-08-30 09:13:02.000000')
            ->and($query['param_to'] ?? '')->toBe('2026-08-30 09:19:02.000000');

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
 * written in the reader's timezone. It used to bound the span query and be
 * retried against the summary only when it found nothing; now the summary's own
 * window is the one read whenever a summary exists, so a wrong `ts` costs
 * nothing and cannot be mistaken for expired spans.
 */
test('a stale timestamp never narrows the window the summary already reports', function () {
    [$user, $team] = traceTeam();

    $spanQueries = 0;
    $windows = [];

    Http::fake(function (Request $request) use (&$spanQueries, &$windows) {
        if (str_contains($request->body(), 'FROM trace_summary')) {
            return Http::response(traceSummaryResponse());
        }

        if (str_contains($request->body(), 'SpanAttributes')) {
            $spanQueries++;
            $windows[] = clickHouseQuery($request)['param_from'] ?? '';

            return Http::response(spanResponse());
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

    // Once, against the summary's window — never against the bad `ts`.
    expect($spanQueries)->toBe(1)
        ->and($windows)->toBe(['2026-08-30 09:14:01.184000']);
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
 * The panel reaches the shared resolver, so a stale `ts` is harmless here too —
 * this is the assertion that would fail if the logic were ever copied instead of
 * shared.
 */
test('the panel endpoint reads the summary window regardless of the timestamp', function () {
    [$user, $team] = traceTeam();

    $windows = [];

    Http::fake(function (Request $request) use (&$windows) {
        if (str_contains($request->body(), 'FROM trace_summary')) {
            return Http::response(traceSummaryResponse());
        }

        $windows[] = clickHouseQuery($request)['param_from'] ?? '';

        return Http::response(spanResponse());
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

    expect($windows)->toBe(['2026-08-30 09:14:01.184000']);
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

        // A trace changes whenever a block of its spans lands, so the
        // candidate is any trace with a block that ENDS after the cursor —
        // which is what re-sends a trace whose root arrived last — and it
        // comes back aggregated over every block it has (R13).
        expect($body)->toContain('FROM trace_index')
            ->toContain('End > {after:DateTime64(9)}')
            ->not->toContain('Start > {after:DateTime64(9)}')
            // The bound the list carries, and the one the tail must not.
            ->not->toContain('Start <= {to:DateTime64(9)}')
            // The window's start still holds, on the whole trace's min(Start).
            ->toContain('HAVING min(Start) >= {from:DateTime64(9)}')
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

/*
 * Span links, the other way a span says where it belongs. A root whose parent
 * lives in a different trace cannot point at it through the tree, so the
 * exporter records a link instead — and until the query selected these columns
 * the page had no way to say so.
 */
test('the waterfall carries each span links, position aligned', function () {
    [$user, $team] = traceTeam();

    $linked = str_repeat('c', 32);

    Http::fake(function (Request $request) use ($linked) {
        if (str_contains($request->body(), 'FROM otel_traces')) {
            return Http::response(spanResponse([
                'Links.TraceId' => [$linked, str_repeat('d', 32)],
                'Links.SpanId' => [str_repeat('e', 16), str_repeat('f', 16)],
                'Links.TraceState' => ['vendor=1', ''],
                'Links.Attributes' => [['link.type' => 'parent_of'], []],
            ]));
        }

        return Http::response(traceSummaryResponse(['TraceId' => $linked]));
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('spans.0.links', 2)
            ->where('spans.0.links.0.traceId', $linked)
            ->where('spans.0.links.0.spanId', str_repeat('e', 16))
            ->where('spans.0.links.0.traceState', 'vendor=1')
            // Asserted whole: the attribute key itself contains a dot, which
            // the fluent path syntax would otherwise read as nesting.
            ->where('spans.0.links.0.attributes', ['link.type' => 'parent_of'])
            // The second link's empty attribute map must stay its own, not
            // borrow the first one's (R12).
            ->where('spans.0.links.1.attributes', [])
            /*
             * A link names a trace; naming one is not having it. The page is
             * told which of them this instance actually holds, because the
             * difference decides whether the link is offered as a way out.
             */
            ->has('linkedTraces.'.$linked)
            ->where('linkedTraces.'.$linked.'.rootName', 'POST /checkout')
            ->etc()
        );

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        // The waterfall query, not the root-resource lookup beside it.
        if (! str_contains($body, 'SpanAttributes')) {
            return false;
        }

        expect($body)->toContain('`Links.TraceId`')
            ->toContain('`Links.SpanId`')
            ->toContain('`Links.TraceState`')
            ->toContain('`Links.Attributes`');

        return true;
    });
});

test('a linked trace this instance does not hold is reported as absent', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'FROM otel_traces')) {
            return Http::response(spanResponse([
                'Links.TraceId' => [str_repeat('c', 32)],
                'Links.SpanId' => [str_repeat('e', 16)],
                'Links.TraceState' => [''],
                'Links.Attributes' => [['link.type' => 'parent_of']],
            ]));
        }

        // The trace's own summary exists; the linked one does not.
        if (str_contains($request->body(), 'TraceId IN {traceIds:Array(String)}')) {
            return Http::response('');
        }

        return Http::response(traceSummaryResponse());
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('spans.0.links', 1)
            // Empty, not missing: the page distinguishes "we hold it" from
            // "it was named", and renders the second as a dead end that says so.
            ->where('linkedTraces', [])
            ->etc()
        );
});

test('a link back into the same trace is never looked up', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'FROM otel_traces')) {
            return Http::response(spanResponse([
                'Links.TraceId' => [str_repeat('a', 32)],
                'Links.SpanId' => [str_repeat('e', 16)],
                'Links.TraceState' => [''],
                'Links.Attributes' => [[]],
            ]));
        }

        return Http::response(traceSummaryResponse());
    });

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('a', 32),
            'ts' => '2026-08-30T09:14:02Z',
        ]))
        ->assertOk();

    // The reader is already in that trace, so there is nothing to resolve.
    Http::assertNotSent(fn (Request $request) => str_contains(
        $request->body(),
        'TraceId IN {traceIds:Array(String)}',
    ));
});

/*
 * The strip above the list. Read the way the list reads (R13): candidates from
 * trace_index by the hour, every candidate re-aggregated over all its blocks on
 * trace_summary (R11), and only then placed in the bucket its true min(Start)
 * falls in. Counting index rows would count a split trace twice and could not
 * count errors at all.
 */
test('the trace histogram is deferred, bucketed over the window and read the R13 way', function () {
    [$user, $team, $project] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'FailedTraces')) {
            return Http::response(collect([
                    ['Bucket' => '2026-08-30 09:00:00.000000000', 'Traces' => 12, 'FailedTraces' => 2],
                    ['Bucket' => '2026-08-30 09:30:00.000000000', 'Traces' => 5, 'FailedTraces' => 0],
                ])->map(fn(array $row): string => (string)json_encode($row))->implode("\n") . "\n");
        }

        return Http::response(traceSummaryResponse());
    });

    $this->actingAs($user)
        ->get(route('traces.index', [
            'current_team' => $team->slug,
            'from' => '2026-08-30T09:00:00Z',
            'to' => '2026-08-30T10:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->component('traces/Index')
            ->missing('histogram')
            ->loadDeferredProps(fn(Assert $reload) => $reload
                ->where('histogram.unavailable', false)
                // A one hour window lands on 300 second buckets: 12 plus the
                // closing edge, every one present even where nothing happened.
                ->where('histogram.intervalSeconds', 300)
                ->has('histogram.buckets', 13)
                ->where('histogram.total', 17)
                ->where('histogram.errors', 2)
                ->where('histogram.buckets.0.at', '2026-08-30 09:00:00.000000')
                ->where('histogram.buckets.0.traces', 12)
                ->where('histogram.buckets.0.errors', 2)
                ->where('histogram.buckets.1.traces', 0)
                ->where('histogram.buckets.6.traces', 5)
                ->etc()
            )
        );

    Http::assertSent(function (Request $request) use ($project) {
        $body = $request->body();

        if (!str_contains($body, 'FailedTraces')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($body)->toContain('toStartOfInterval(Started, toIntervalSecond({bucketSeconds:UInt32}))')
            ->toContain('countIf(Errors > 0) AS FailedTraces')
            ->toContain('FROM trace_summary')
            ->toContain('FROM trace_index')
            ->toContain('Hour >= toStartOfHour({candidateFrom:DateTime64(9)})')
            ->toContain('GROUP BY ProjectId, TraceId')
            ->toContain('HAVING min(Start) >= {from:DateTime64(9)} AND min(Start) <= {to:DateTime64(9)}')
            ->not->toContain('WHERE Start >=')
            ->and($query['param_bucketSeconds'] ?? '')->toBe('300')
            ->and($query['param_projectIds'] ?? '')->toBe("['" . $project->id . "']");

        return true;
    });
});

test('the trace histogram keeps the list filters and stays off the latency page', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(traceSummaryResponse())]);

    $this->actingAs($user)
        ->get(route('traces.index', [
            'current_team' => $team->slug,
            'errors' => '1',
            'service' => 'checkout',
            'min_duration' => 250,
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page->loadDeferredProps(
            fn(Assert $reload) => $reload->has('histogram.buckets'),
        ));

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        if (!str_contains($body, 'FailedTraces')) {
            return false;
        }

        expect($body)->toContain('sum(ErrorCount) > 0')
            ->toContain('max(RootService) = {service:String}')
            ->toContain("dateDiff('millisecond', min(Start), max(End)) >= {minDuration:UInt32}")
            ->and(clickHouseQuery($request)['param_service'] ?? '')->toBe('checkout');

        return true;
    });

    $this->actingAs($user)
        ->get(route('traces.latency', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page->missing('histogram')->etc());
});

test('an overloaded clickhouse leaves the histogram unavailable with every bucket present', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($user)
        ->get(route('traces.index', [
            'current_team' => $team->slug,
            'from' => '2026-08-30T09:00:00Z',
            'to' => '2026-08-30T10:00:00Z',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page->loadDeferredProps(
            fn(Assert $reload) => $reload
                ->where('histogram.unavailable', true)
                ->has('histogram.buckets', 13)
                ->where('histogram.total', 0)
                ->etc()
        ));
});

/*
 * The toolbar's service picker. Shared by both tabs through shared(), so the
 * same names are offered on both, and read from the cheapest pair of tables
 * that can answer: trace_index nominates the week's traces by the hour, and
 * trace_summary is asked for the distinct non-empty root services among them.
 */
test('the toolbar service list is deferred, shared by both tabs and read through the index', function () {
    [$user, $team, $project] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'SELECT DISTINCT RootService')) {
            return Http::response("{\"RootService\":\"checkout\"}\n{\"RootService\":\"ledger\"}\n");
        }

        return Http::response(traceSummaryResponse());
    });

    $this->actingAs($user)
        ->get(route('traces.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->missing('services')
            ->loadDeferredProps(fn(Assert $reload) => $reload
                ->where('services', ['checkout', 'ledger'])
                ->etc()
            )
        );

    Http::assertSent(function (Request $request) use ($project) {
        $body = $request->body();

        if (!str_contains($body, 'SELECT DISTINCT RootService')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($body)->toContain('FROM trace_summary')
            ->toContain("RootService != ''")
            ->toContain('FROM trace_index')
            ->toContain('Hour >= toStartOfHour({from:DateTime64(9)})')
            ->and($query['param_projectIds'] ?? '')->toBe("['" . $project->id . "']")
            ->and($query['param_rowLimit'] ?? '')->toBe('200');

        return true;
    });

    $this->actingAs($user)
        ->get(route('traces.latency', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->component('traces/Latency')
            ->loadDeferredProps(fn(Assert $reload) => $reload
                ->where('services', ['checkout', 'ledger'])
                ->etc()
            )
        );
});

/*
 * Summaries outlive spans (90 days against 30). Whether a row can still be
 * opened is judged once, server-side, so the list, the poll and every link
 * agree about the same row.
 */
test('a trace older than the span retention is flagged as expired', function () {
    [$user, $team] = traceTeam();

    $old = Carbon::now()->subDays(40)->format('Y-m-d H:i:s.000000000');
    $fresh = Carbon::now()->subHours(2)->format('Y-m-d H:i:s.000000000');

    Http::fake(function (Request $request) use ($old, $fresh) {
        if (str_contains($request->body(), 'sum(SpanCount)')) {
            return Http::response(
                traceSummaryResponse(['TraceId' => str_repeat('a', 32), 'Started' => $old, 'Ended' => $old]) . "\n"
                . traceSummaryResponse(['TraceId' => str_repeat('b', 32), 'Started' => $fresh, 'Ended' => $fresh]) . "\n",
            );
        }

        return Http::response('');
    });

    $this->actingAs($user)
        ->getJson(route('traces.tail', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertJsonPath('rows.0.spansExpired', true)
        ->assertJsonPath('rows.1.spansExpired', false);
});

/*
 * "N logs for this trace" in the trace header. Counted in the trace's own
 * window with a minute of slack, the same window the link opens, so the
 * number beside the link is the number the click delivers. R4 throughout: the
 * ProjectId and time predicates stay and TraceId is appended to them.
 */
test('the trace page counts the logs the trace wrote inside its own window', function () {
    [$user, $team, $project] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'FROM otel_logs')) {
            return Http::response('{"Total":7}');
        }

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
        ->assertInertia(fn(Assert $page) => $page
            ->where('logs.count', 7)
            // 09:14:02.184 – 09:14:02.436, a minute either side.
            ->where('logs.from', '2026-08-30T09:13:02+00:00')
            ->where('logs.to', '2026-08-30T09:15:02+00:00')
            ->etc()
        );

    Http::assertSent(function (Request $request) use ($project) {
        $body = $request->body();

        if (!str_contains($body, 'FROM otel_logs')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($body)->toContain('SELECT count() AS Total')
            ->toContain('ProjectId IN {projectIds:Array(String)}')
            ->toContain('Timestamp >= {from:DateTime64(9)}')
            ->toContain('Timestamp <= {to:DateTime64(9)}')
            ->toContain('TraceId = {traceId:String}')
            ->and($query['param_projectIds'] ?? '')->toBe("['" . $project->id . "']")
            ->and($query['param_traceId'] ?? '')->toBe(str_repeat('a', 32))
            ->and($query['param_from'] ?? '')->toBe('2026-08-30 09:13:02.184000')
            ->and($query['param_to'] ?? '')->toBe('2026-08-30 09:15:02.436000');

        return true;
    });
});

test('a trace with no summary has no log count to offer', function () {
    [$user, $team] = traceTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->actingAs($user)
        ->get(route('traces.show', [
            'current_team' => $team->slug,
            'trace' => str_repeat('f', 32),
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page->where('logs', null)->etc());

    Http::assertNotSent(fn(Request $request) => str_contains($request->body(), 'FROM otel_logs'));
});

test('an overloaded log store withholds the count but keeps the window for the link', function () {
    [$user, $team] = traceTeam();

    Http::fake(function (Request $request) {
        if (str_contains($request->body(), 'FROM otel_logs')) {
            return Http::response('Service Unavailable', 503);
        }

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
        ->assertInertia(fn(Assert $page) => $page
            // Null, not zero: "could not count" must not read as "wrote nothing".
            ->where('logs.count', null)
            ->where('logs.from', '2026-08-30T09:13:02+00:00')
            ->where('unavailable', false)
            ->etc()
        );
});
