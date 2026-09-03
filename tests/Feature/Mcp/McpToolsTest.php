<?php

use App\Enums\TeamRole;
use App\Mcp\Servers\BilisServer;
use App\Mcp\Tools\ErrorSummaryTool;
use App\Mcp\Tools\GetTraceTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListTeamsTool;
use App\Mcp\Tools\ListTracesTool;
use App\Mcp\Tools\SearchLogsTool;
use App\Mcp\Tools\ServiceLatencyTool;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A team with one member and one project named "Checkout".
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function mcpTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $user->switchTeam($team);

    return [$user, $team, $project];
}

/**
 * A JSONEachRow body holding one log row.
 */
function mcpLogRow(string $body = 'Stripe webhook timed out'): string
{
    return (string) json_encode([
        'ProjectId' => '1',
        'Timestamp' => '2026-09-02 10:00:00.000000000',
        'TraceId' => str_repeat('a', 32),
        'SpanId' => str_repeat('b', 16),
        'SeverityText' => 'ERROR',
        'SeverityNumber' => 17,
        'ServiceName' => 'billing',
        'Body' => $body,
        'ScopeName' => 'scope',
        'ScopeVersion' => '1.0',
        'ResourceAttributes' => ['host' => 'web-1'],
        'LogAttributes' => ['request_id' => 'abc'],
    ])."\n";
}

/**
 * A JSONEachRow body holding one aggregated trace summary row.
 *
 * The keys are the query's output aliases rather than the column names — see
 * SCHEMA.md R11 for why the two must differ.
 *
 * @param  array<string, mixed>  $overrides
 */
function mcpTraceSummary(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'TraceId' => str_repeat('a', 32),
        'TraceRootName' => 'POST /checkout',
        'TraceRootService' => 'checkout',
        'Started' => '2026-09-02 09:14:02.184000000',
        'Ended' => '2026-09-02 09:14:02.436000000',
        'TraceSpanCount' => 14,
        'TraceErrorCount' => 2,
    ], $overrides));
}

/**
 * A JSONEachRow body holding one span row.
 *
 * @param  array<string, mixed>  $overrides
 */
function mcpSpan(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'Timestamp' => '2026-09-02 09:14:02.184000000',
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
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123, 'clickhouse.database' => 'bilis']);
});

test('list-teams names the team every other tool defaults to', function () {
    [$user, $team] = mcpTeam();

    BilisServer::actingAs($user)
        ->tool(ListTeamsTool::class)
        ->assertOk()
        ->assertSee($team->slug)
        ->assertSee('"current":true');
});

test('list-projects reports whether a project has ever received anything', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response("{\"1\":1}\n")]);

    BilisServer::actingAs($user)
        ->tool(ListProjectsTool::class)
        ->assertOk()
        ->assertSee('checkout')
        ->assertSee('"hasLogs":true');
});

test('search-logs sends the project id, never the slug', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(mcpLogRow())]);

    BilisServer::actingAs($user)
        ->tool(SearchLogsTool::class, ['project' => 'checkout', 'severity' => ['error']])
        ->assertOk()
        ->assertSee('Stripe webhook timed out');

    Http::assertSent(function (Request $request): bool {
        $query = clickHouseQuery($request);

        // The ProjectId predicate is bound as an id; a slug must never reach SQL.
        expect($query)->toHaveKey('param_projectIds');
        expect($query['param_projectIds'])->not->toContain('checkout');

        return true;
    });
});

test('search-logs refuses a project the team does not have, and asks no question of the store', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(mcpLogRow())]);

    BilisServer::actingAs($user)
        ->tool(SearchLogsTool::class, ['project' => 'somebody-elses-app'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

test('a team the user is not a member of is refused without revealing whether it exists', function () {
    [$user] = mcpTeam();
    Team::factory()->create(['name' => 'Stranger', 'slug' => 'stranger']);

    $response = BilisServer::actingAs($user)
        ->tool(ListProjectsTool::class, ['team' => 'stranger'])
        ->assertHasErrors();

    $response->assertSee('not a member');
    $response->assertDontSee('Stranger');
});

test('search-logs rejects a malformed trace id rather than scanning for it', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(mcpLogRow())]);

    BilisServer::actingAs($user)
        ->tool(SearchLogsTool::class, ['trace_id' => 'not-a-trace'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

test('a busy store is reported as unavailable rather than as silence', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response('overloaded', 503)]);

    BilisServer::actingAs($user)
        ->tool(SearchLogsTool::class)
        ->assertOk()
        ->assertSee('"unavailable":true');
});

test('error-summary folds repeated lines into one problem with a count', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(
        mcpLogRow().mcpLogRow().mcpLogRow('Card declined')
    )]);

    BilisServer::actingAs($user)
        ->tool(ErrorSummaryTool::class)
        ->assertOk()
        ->assertSee('"count":2')
        ->assertSee('Card declined');
});

test('list-traces returns the traces worth opening', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(mcpTraceSummary())]);

    BilisServer::actingAs($user)
        ->tool(ListTracesTool::class, ['errors_only' => true])
        ->assertOk()
        ->assertSee('POST /checkout');
});

test('get-trace renders the request as a waterfall', function () {
    [$user] = mcpTeam();

    Http::fakeSequence('127.0.0.1:8123/*')
        ->push(mcpTraceSummary())
        ->push(mcpSpan());

    BilisServer::actingAs($user)
        ->tool(GetTraceTool::class, ['trace_id' => str_repeat('a', 32)])
        ->assertOk()
        ->assertSee('"state":"rendered"')
        ->assertSee('"waterfall"')
        ->assertSee('checkout');
});

test('get-trace insists on a real trace id', function () {
    [$user] = mcpTeam();

    BilisServer::actingAs($user)
        ->tool(GetTraceTool::class, ['trace_id' => 'nope'])
        ->assertHasErrors();
});

test('service-latency counts errors with the exporter literal', function () {
    [$user] = mcpTeam();

    Http::fake(['127.0.0.1:8123/*' => Http::response(
        (string) json_encode(['ServiceName' => 'billing', 'Spans' => 100, 'P95' => 250000000, 'P99' => 900000000, 'Errors' => 3])
    )]);

    BilisServer::actingAs($user)
        ->tool(ServiceLatencyTool::class)
        ->assertOk()
        ->assertSee('billing')
        ->assertSee('"p95Ms":250');

    Http::assertSent(function (Request $request): bool {
        // R10: the exporter's String() literal, never the proto enum name.
        expect(clickHouseQuery($request)['param_errorStatus'] ?? null)->toBe('Error');

        return true;
    });
});
