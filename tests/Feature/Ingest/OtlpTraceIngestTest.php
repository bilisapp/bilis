<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    $this->plainTextKey = 'bilis_'.str_repeat('b', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();
});

/**
 * A one-span export request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function traceExport(array $overrides = []): array
{
    return [
        'resourceSpans' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
            ]],
            'scopeSpans' => [[
                'scope' => ['name' => 'checkout.payments', 'version' => '1.4.0'],
                'spans' => [array_merge([
                    'traceId' => '5b8efff798038103d269b633813fc60c',
                    'spanId' => 'eee19b7ec3c1b174',
                    'name' => 'POST /checkout',
                    'kind' => 2,
                    'startTimeUnixNano' => '1735689600000000000',
                    'endTimeUnixNano' => '1735689600250000000',
                    'status' => ['code' => 2, 'message' => 'checkout failed'],
                ], $overrides)],
            ]],
        ]],
    ];
}

/**
 * POST a raw body with the given headers, bypassing the JSON helpers.
 *
 * @param  array<string, string>  $headers
 */
function postTraces(string $body, array $headers = []): TestResponse
{
    return test()->call('POST', '/api/v1/traces', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.test()->plainTextKey,
        'CONTENT_TYPE' => 'application/json',
        ...$headers,
    ], $body);
}

test('a valid otlp trace export inserts correctly shaped rows', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport([
            'attributes' => [['key' => 'http.method', 'value' => ['stringValue' => 'POST']]],
            'events' => [[
                'timeUnixNano' => '1735689600100000000',
                'name' => 'exception',
                'attributes' => [['key' => 'exception.type', 'value' => ['stringValue' => 'RuntimeException']]],
            ]],
        ]));

    $response->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray([
                'TraceId' => '5b8efff798038103d269b633813fc60c',
                'SpanId' => 'eee19b7ec3c1b174',
                // No parent: the root marker trace_summary_mv looks for.
                'ParentSpanId' => '',
                'SpanName' => 'POST /checkout',
                // The exporter's literals, not the proto's enum names (R10).
                'SpanKind' => 'Server',
                'StatusCode' => 'Error',
                'StatusMessage' => 'checkout failed',
                'ServiceName' => 'checkout',
                'ScopeName' => 'checkout.payments',
                'ScopeVersion' => '1.4.0',
                'Duration' => 250000000,
                // Never from the payload (SCHEMA.md R2).
                'ProjectId' => (string) $this->project->id,
            ]);

        expect($rows[0]['Events.Name'])->toBe(['exception'])
            ->and($rows[0]['SpanAttributes'])->toBe(['http.method' => 'POST']);

        return str_contains((string) $request->url(), 'async_insert=1');
    });
});

test('spans are written to the traces table, not the logs table', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport())
        ->assertOk();

    Http::assertSent(fn (Request $request) => str_contains(
        urldecode((string) $request->url()),
        'INSERT INTO otel_traces',
    ));
});

/*
 * Empty maps have to reach ClickHouse as JSON objects. An empty PHP array
 * encodes as `[]`, which JSONEachRow rejects for a Map — and with
 * wait_for_async_insert=0 the server acks first and drops the row in silence.
 * `Events.Attributes` is the sharper edge: the outer array is right, it is each
 * element that must be `{}`.
 */
test('empty attribute maps are serialized as JSON objects', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport([
            'events' => [
                ['timeUnixNano' => '1735689600100000000', 'name' => 'no-attributes'],
                ['timeUnixNano' => '1735689600200000000', 'name' => 'also-none'],
            ],
        ]))
        ->assertOk();

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        expect($body)->toContain('"SpanAttributes":{}')
            ->and($body)->toContain('"Events.Attributes":[{},{}]')
            // A genuinely empty list stays a list.
            ->and($body)->toContain('"Links.TraceId":[]');

        return true;
    });
});

/*
 * PHP turns the attribute key "0" into the integer 0, and json_encode then
 * writes the map as the list ["x"]. ClickHouse refuses that for a Map — after
 * the async insert has been acked — and the whole block is lost with it.
 */
test('numeric attribute keys still reach ClickHouse as JSON objects', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport([
            'attributes' => [['key' => '0', 'value' => ['stringValue' => 'x']]],
            'events' => [['timeUnixNano' => '1735689600100000000', 'name' => 'e', 'attributes' => [['key' => '1', 'value' => ['stringValue' => 'y']]]]],
            'links' => [['traceId' => str_repeat('c', 32), 'spanId' => str_repeat('d', 16), 'attributes' => [['key' => '0', 'value' => ['stringValue' => 'z']]]]],
        ]))
        ->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        expect($body)->toContain('"SpanAttributes":{"0":"x"}')
            ->and($body)->toContain('"Events.Attributes":[{"1":"y"}]')
            ->and($body)->toContain('"Links.Attributes":[{"0":"z"}]');

        return true;
    });
});

test('a span with an unusable id or start time is counted as rejected, never stored or 400', function (array $overrides) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport($overrides))
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedSpans', 1);

    Http::assertNothingSent();
})->with([
    'short trace id' => [['traceId' => 'abc']],
    'all-zero span id' => [['spanId' => str_repeat('0', 16)]],
    'thirty-digit start' => [['startTimeUnixNano' => str_repeat('9', 30)]],
    'seconds as nanos' => [['startTimeUnixNano' => '1735689600']],
]);

test('the protobuf encoding is accepted and maps identically to json', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $protobuf = (string) file_get_contents(base_path('tests/Fixtures/otlp/otlp-traces-export.bin'));

    postTraces($protobuf, ['CONTENT_TYPE' => 'application/x-protobuf'])->assertOk();

    Http::assertSent(function (Request $request) {
        expect(insertedRows($request))->toHaveCount(2);

        return true;
    });
});

test('a gzipped body is inflated', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = (string) gzencode((string) json_encode(traceExport()));

    postTraces($body, ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertOk();

    Http::assertSent(fn (Request $request) => count(insertedRows($request)) === 1);
});

/*
 * The invariant: ingest never blames the client for a payload. OTel clients
 * treat 4xx as permanent and drop the batch, so a 400 here loses data that a
 * partialSuccess would have kept.
 */
test('a malformed payload is reported as a partial success, never a 400', function (mixed $payload) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', $payload);

    $response->assertOk()
        ->assertJsonStructure(['partialSuccess' => ['rejectedSpans', 'errorMessage']]);
})->with([
    'resourceSpans of the wrong type' => [['resourceSpans' => 'nonsense']],
    'a span that is not an object' => [['resourceSpans' => [['scopeSpans' => [['spans' => ['nope']]]]]]],
]);

test('an unreadable protobuf body is a rejected payload, not a client error', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = postTraces('definitely not protobuf', ['CONTENT_TYPE' => 'application/x-protobuf'])
        ->assertOk();

    /*
     * Answered in the encoding it arrived in, and with no `rejected` field:
     * proto3 omits a zero, and an unreadable body rejects the payload without
     * ever learning how many spans were inside it.
     */
    expect($response->headers->get('Content-Type'))->toBe('application/x-protobuf')
        ->and(decodeOtlpResponse($response->getContent()))
        ->toBe(['errorMessage' => 'Request body could not be read as an OTLP ExportTraceServiceRequest.']);
});

test('a clickhouse failure answers 503 with a retry hint, never 4xx', function () {
    $this->mock(ClickHouseClient::class, function ($mock) {
        $mock->shouldReceive('insert')->andThrow(
            ClickHouseException::fromInvalidResponse('ClickHouse is unavailable.'),
        );
    });

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/traces', traceExport())
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

test('an unsupported content encoding is refused with a message naming what works', function () {
    postTraces('compressed', ['HTTP_CONTENT_ENCODING' => 'zstd'])
        ->assertStatus(415)
        ->assertJsonPath('message', 'Content-Encoding zstd is not supported. Send the body uncompressed or with gzip or deflate.');
});

test('the endpoint refuses an unauthenticated request', function () {
    $this->postJson('/api/v1/traces', traceExport())->assertUnauthorized();
});

test('the endpoint refuses an unknown api key', function () {
    $this->withHeaders(['Authorization' => 'Bearer bilis_'.str_repeat('z', 40)])
        ->postJson('/api/v1/traces', traceExport())
        ->assertUnauthorized();
});
