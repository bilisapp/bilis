<?php

use App\Enums\TeamRole;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\TaskRenderer;
use App\Services\Autofix\TraceContextBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const TRACE_CONTEXT_TRACE_ID = 'abcdefabcdefabcdefabcdefabcdef12';

const TRACE_CONTEXT_SPAN_ID = 'feedfacefeedface';

/**
 * A team whose one project ships from a repository that takes every service.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: ProjectRepository}
 */
function traceContextTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    return [$user, $team, $project, $repository];
}

/**
 * A JSONEachRow trace_summary row, in the query's output aliases (R11).
 */
function traceContextSummaryRow(): string
{
    return (string)json_encode([
        'TraceId' => TRACE_CONTEXT_TRACE_ID,
        'TraceRootName' => 'POST /checkout',
        'TraceRootService' => 'checkout',
        'Started' => '2026-08-26 10:00:00.000000000',
        'Ended' => '2026-08-26 10:00:00.252000000',
        'TraceSpanCount' => 3,
        'TraceErrorCount' => 1,
    ]);
}

/**
 * Three JSONEachRow otel_traces rows: a server root, a failed client span, and
 * the span the log line was written from.
 */
function traceContextSpanRows(): string
{
    $base = [
        'Timestamp' => '2026-08-26 10:00:00.000000000',
        'TraceId' => TRACE_CONTEXT_TRACE_ID,
        'ParentSpanId' => '',
        'SpanKind' => 'Internal',
        'ServiceName' => 'checkout',
        'Duration' => 10_000_000,
        'StatusCode' => 'Unset',
        'StatusMessage' => '',
        'SpanAttributes' => [],
        'Events.Timestamp' => [],
        'Events.Name' => [],
        'Events.Attributes' => [],
        'Links.TraceId' => [],
        'Links.SpanId' => [],
        'Links.TraceState' => [],
        'Links.Attributes' => [],
    ];

    return implode("\n", [
            json_encode([...$base, 'SpanId' => 'aaaaaaaaaaaaaaaa', 'SpanName' => 'POST /checkout', 'SpanKind' => 'Server', 'Duration' => 252_000_000, 'SpanAttributes' => ['http.method' => 'POST', 'http.route' => '/checkout', 'http.status_code' => '500']]),
            json_encode([...$base, 'SpanId' => 'bbbbbbbbbbbbbbbb', 'ParentSpanId' => 'aaaaaaaaaaaaaaaa', 'SpanName' => 'stripe.charge', 'SpanKind' => 'Client', 'ServiceName' => 'payments', 'StatusCode' => 'Error', 'StatusMessage' => 'card declined']),
            json_encode([...$base, 'SpanId' => TRACE_CONTEXT_SPAN_ID, 'ParentSpanId' => 'aaaaaaaaaaaaaaaa', 'SpanName' => 'Charger::run', 'Events.Timestamp' => ['2026-08-26 10:00:00.200000000'], 'Events.Name' => ['exception'], 'Events.Attributes' => [['exception.type' => 'App\\Exceptions\\PaymentFailed', 'exception.message' => 'card declined']]]),
        ]) . "\n";
}

/**
 * Fake ClickHouse so the summary lookup and the span read each get their own answer.
 */
function fakeTraceStorage(mixed $summary, mixed $spans): void
{
    Http::fake([
        '127.0.0.1:8123/*' => function (Request $request) use ($summary, $spans) {
            $sql = $request->body();

            if (str_contains($sql, 'FROM trace_summary')) {
                return $summary instanceof Closure ? $summary() : Http::response($summary);
            }

            if (str_contains($sql, 'FROM otel_traces')) {
                return $spans instanceof Closure ? $spans() : Http::response($spans);
            }

            return Http::response('');
        },
    ]);
}

/**
 * A log row in the shape the viewer posts back.
 *
 * @return array<string, mixed>
 */
function traceContextRow(Project $project, array $overrides = []): array
{
    return [
        'project' => (string)$project->getKey(),
        'timestamp' => '2026-08-26 10:00:00.000000000',
        'severityText' => 'ERROR',
        'severityNumber' => 17,
        'serviceName' => 'checkout',
        'body' => "App\\Exceptions\\PaymentFailed: card declined\n#0 /app/app/Services/Billing/Charger.php(42): charge()",
        'traceId' => TRACE_CONTEXT_TRACE_ID,
        'spanId' => TRACE_CONTEXT_SPAN_ID,
        'scopeName' => 'bilis.ingest',
        'scopeVersion' => '1.0',
        'logAttributes' => [],
        'resourceAttributes' => [],
        ...$overrides,
    ];
}

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
        'autofix.enabled' => true,
        'autofix.llm.api_key' => 'sk-ant-instance-fallback',
    ]);

    Queue::fake();
});

test('the builder renders the waterfall and marks the span the log line came from', function () {
    fakeTraceStorage(traceContextSummaryRow(), traceContextSpanRows());

    $context = app(TraceContextBuilder::class)->build(['7'], strtoupper(TRACE_CONTEXT_TRACE_ID), TRACE_CONTEXT_SPAN_ID);

    expect($context['state'])->toBe(TraceContextBuilder::STATE_RENDERED)
        ->and($context['trace_id'])->toBe(TRACE_CONTEXT_TRACE_ID)
        ->and($context['root_name'])->toBe('POST /checkout')
        ->and($context['root_service'])->toBe('checkout')
        ->and($context['span_count'])->toBe(3)
        ->and($context['error_count'])->toBe(1)
        ->and($context['duration_ms'])->toBe(252.0)
        ->and($context['rendered_spans'])->toBe(3)
        ->and($context['omitted_spans'])->toBe(0)
        ->and($context['waterfall'])
        ->toContain('     checkout POST /checkout [Server] 252ms | http.method=POST http.route=/checkout http.status_code=500')
        ->toContain('  !!   payments stripe.charge [Client] 10ms Error: card declined')
        ->toContain('>>     checkout Charger::run [Internal] 10ms | exception=App\\Exceptions\\PaymentFailed: card declined');

    // The project ids are bound as parameters on both reads, never interpolated.
    Http::assertSent(function (Request $request): bool {
        $query = clickHouseQuery($request);

        return str_contains($request->body(), 'FROM otel_traces')
            && ($query['param_projectIds'] ?? null) === "['7']"
            && ($query['param_traceId'] ?? null) === TRACE_CONTEXT_TRACE_ID;
    });
});

test('the span read is bounded by the start and end the summary reports', function () {
    fakeTraceStorage(traceContextSummaryRow(), traceContextSpanRows());

    app(TraceContextBuilder::class)->build(['7'], TRACE_CONTEXT_TRACE_ID, TRACE_CONTEXT_SPAN_ID);

    Http::assertSent(function (Request $request): bool {
        if (!str_contains($request->body(), 'FROM otel_traces')) {
            return false;
        }

        $query = clickHouseQuery($request);

        expect($query['param_from'])->toStartWith('2026-08-26 09:59:59')
            ->and($query['param_to'])->toStartWith('2026-08-26 10:00:01');

        return true;
    });
});

test('a summary whose spans have aged out is recorded as expired', function () {
    fakeTraceStorage(traceContextSummaryRow(), '');

    $context = app(TraceContextBuilder::class)->build(['7'], TRACE_CONTEXT_TRACE_ID, TRACE_CONTEXT_SPAN_ID);

    expect($context['state'])->toBe(TraceContextBuilder::STATE_EXPIRED)
        ->and($context['span_count'])->toBe(3)
        ->and($context['waterfall'])->toBeNull();
});

test('a trace id nothing is stored for is recorded as missing, and the spans are never read', function () {
    fakeTraceStorage('', traceContextSpanRows());

    $context = app(TraceContextBuilder::class)->build(['7'], TRACE_CONTEXT_TRACE_ID, TRACE_CONTEXT_SPAN_ID);

    expect($context['state'])->toBe(TraceContextBuilder::STATE_MISSING)
        ->and($context['waterfall'])->toBeNull();

    Http::assertNotSent(fn(Request $request): bool => str_contains($request->body(), 'FROM otel_traces'));
});

test('an overloaded ClickHouse is recorded as unavailable rather than thrown', function () {
    fakeTraceStorage(fn() => Http::response('Code: 202. DB::Exception: Too many simultaneous queries.', 503), '');

    $context = app(TraceContextBuilder::class)->build(['7'], TRACE_CONTEXT_TRACE_ID, TRACE_CONTEXT_SPAN_ID);

    expect($context['state'])->toBe(TraceContextBuilder::STATE_UNAVAILABLE);
});

test('a ClickHouse error that is not an overload is still swallowed, because the trace is only an enrichment', function () {
    fakeTraceStorage(fn() => Http::response('Code: 60. DB::Exception: Unknown table.', 404), '');

    $context = app(TraceContextBuilder::class)->build(['7'], TRACE_CONTEXT_TRACE_ID, TRACE_CONTEXT_SPAN_ID);

    expect($context['state'])->toBe(TraceContextBuilder::STATE_UNAVAILABLE);
});

test('a fix job raised from a traced log line carries the waterfall and the task shows it', function () {
    [$user, $team, $project] = traceContextTeam();
    fakeTraceStorage(traceContextSummaryRow(), traceContextSpanRows());

    $this->actingAs($user)
        ->post(route('autofix.from-log', ['current_team' => $team->slug]), traceContextRow($project))
        ->assertRedirect();

    $job = FixJob::query()->sole();

    expect($job->error_context['trace']['state'])->toBe('rendered')
        ->and($job->error_context['trace']['span_id'])->toBe(TRACE_CONTEXT_SPAN_ID)
        ->and($job->error_context['trace']['waterfall'])->toContain('>>     checkout Charger::run');

    $task = app(TaskRenderer::class)->render($job);

    expect($task['instructions'])->toContain('trace waterfall')
        ->and($task['context'])
        ->toContain("\nTrace:\n")
        ->toContain('Trace ' . TRACE_CONTEXT_TRACE_ID . ' (checkout POST /checkout): 3 spans, 1 with Error status, 252ms total.')
        ->toContain('>>     checkout Charger::run')
        ->toEndWith(TaskRenderer::CONTEXT_END);

    // The waterfall sits after the log evidence, inside the untrusted block.
    expect(strpos($task['context'], 'Sample log lines:'))->toBeLessThan(strpos($task['context'], 'Trace:'));
});

test('a fix job raised from an untraced log line carries no trace block at all', function () {
    [$user, $team, $project] = traceContextTeam();
    Http::fake();

    $this->actingAs($user)
        ->post(route('autofix.from-log', ['current_team' => $team->slug]), traceContextRow($project, ['traceId' => '', 'spanId' => '']))
        ->assertRedirect();

    $job = FixJob::query()->sole();

    expect($job->error_context)->not->toHaveKey('trace');

    $task = app(TaskRenderer::class)->render($job);

    expect($task['context'])->not->toContain('Trace:')
        ->and($task['context'])->not->toContain('Trace ');

    Http::assertNothingSent();
});

test('the task explains an expired, missing or unavailable trace in one line', function (string $state, string $expected) {
    $job = ayosJob();
    $job->forceFill([
        'error_context' => [
            ...$job->error_context,
            'trace' => [
                'trace_id' => TRACE_CONTEXT_TRACE_ID,
                'span_id' => TRACE_CONTEXT_SPAN_ID,
                'state' => $state,
                'root_name' => '',
                'root_service' => '',
                'started_at' => '',
                'duration_ms' => 0,
                'span_count' => 3,
                'error_count' => 1,
                'rendered_spans' => 0,
                'omitted_spans' => 0,
                'truncated' => false,
                'waterfall' => null,
            ],
        ],
    ])->save();

    $task = app(TaskRenderer::class)->render($job->fresh());

    expect($task['context'])->toContain("\nTrace:\n" . $expected);
})->with([
    'expired' => ['expired', 'Trace ' . TRACE_CONTEXT_TRACE_ID . ' was referenced by the log line but its spans have expired; only its summary remains (3 spans, 1 with Error status).'],
    'missing' => ['missing', 'Trace ' . TRACE_CONTEXT_TRACE_ID . ' was referenced by the log line but no trace with that id is stored.'],
    'unavailable' => ['unavailable', 'Trace ' . TRACE_CONTEXT_TRACE_ID . ' was referenced by the log line but trace storage could not be read when this job was raised.'],
]);

test('the job page ships the trace context alongside the stack', function () {
    [$user, $team, $project] = traceContextTeam();
    fakeTraceStorage(traceContextSummaryRow(), traceContextSpanRows());

    $this->actingAs($user)
        ->post(route('autofix.from-log', ['current_team' => $team->slug]), traceContextRow($project))
        ->assertRedirect();

    $job = FixJob::query()->sole();

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertInertia(fn(Assert $page) => $page
            ->component('autofix/Show')
            ->where('job.errorContext.trace.state', 'rendered')
            ->where('job.errorContext.trace.trace_id', TRACE_CONTEXT_TRACE_ID)
            ->where('job.errorContext.trace.rendered_spans', 3),
        );
});
