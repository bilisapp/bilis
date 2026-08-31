<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    $this->plainTextKey = 'bilis_'.str_repeat('a', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();
});

test('a valid otlp export inserts correctly shaped rows', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->plainTextKey])
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [[
                'resource' => [
                    'attributes' => [
                        ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                        ['key' => 'host.cpus', 'value' => ['intValue' => '8']],
                        ['key' => 'debug.enabled', 'value' => ['boolValue' => true]],
                        ['key' => 'tags', 'value' => ['arrayValue' => ['values' => [
                            ['stringValue' => 'a'],
                            ['stringValue' => 'b'],
                        ]]]],
                    ],
                ],
                'schemaUrl' => 'https://opentelemetry.io/schemas/1.31.0',
                'scopeLogs' => [[
                    'schemaUrl' => 'https://opentelemetry.io/schemas/1.30.0',
                    'scope' => [
                        'name' => 'bilis.instrumentation',
                        'version' => '1.2.3',
                        'attributes' => [
                            ['key' => 'library.mode', 'value' => ['stringValue' => 'auto']],
                        ],
                    ],
                    'logRecords' => [[
                        'eventName' => 'payment.failed',
                        'timeUnixNano' => '1735689600123456789',
                        'observedTimeUnixNano' => 1735689600987654321,
                        'severityNumber' => 17,
                        'traceId' => '5b8efff798038103d269b633813fc60c',
                        'spanId' => 'eee19b7ec3c1b174',
                        'traceFlags' => 1,
                        'body' => ['stringValue' => 'Payment failed'],
                        'attributes' => [
                            ['key' => 'order.id', 'value' => ['stringValue' => '42']],
                            ['key' => 'retry', 'value' => ['boolValue' => false]],
                        ],
                    ]],
                ]],
            ]],
        ]);

    $response->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray([
                // ProjectId is a String column now, and it is written from the
                // authenticated key rather than defaulted (SCHEMA.md R2).
                'ProjectId' => (string) $this->project->id,
                'Timestamp' => '2025-01-01 00:00:00.123456789',
                'TraceId' => '5b8efff798038103d269b633813fc60c',
                'SpanId' => 'eee19b7ec3c1b174',
                'TraceFlags' => 1,
                'SeverityNumber' => 17,
                'SeverityText' => 'ERROR',
                'ServiceName' => 'checkout',
                'Body' => 'Payment failed',
                'ResourceSchemaUrl' => 'https://opentelemetry.io/schemas/1.31.0',
                'ScopeSchemaUrl' => 'https://opentelemetry.io/schemas/1.30.0',
                'ScopeName' => 'bilis.instrumentation',
                'ScopeVersion' => '1.2.3',
                'EventName' => 'payment.failed',
            ])
            // R6: the derived observed timestamp column is gone from the table,
            // so it must not be written either.
            ->and($rows[0])->not->toHaveKey('ObservedTimestamp')
            ->and(array_keys($rows[0]))->toBe([
                'Timestamp', 'TraceId', 'SpanId', 'TraceFlags', 'SeverityText',
                'SeverityNumber', 'ServiceName', 'Body', 'ResourceSchemaUrl',
                'ResourceAttributes', 'ScopeSchemaUrl', 'ScopeName', 'ScopeVersion',
                'ScopeAttributes', 'LogAttributes', 'EventName', 'ProjectId',
            ])
            ->and($rows[0]['ScopeAttributes'])->toBe(['library.mode' => 'auto'])
            ->and($rows[0]['ResourceAttributes'])->toBe([
                'service.name' => 'checkout',
                'host.cpus' => '8',
                'debug.enabled' => 'true',
                'tags' => '["a","b"]',
            ])
            ->and($rows[0]['LogAttributes'])->toBe([
                'order.id' => '42',
                'retry' => 'false',
            ]);

        return str_contains((string) $request->url(), 'otel_logs');
    });
});

test('severity text is derived from the severity number and back again', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withHeaders(['X-Bilis-Key' => $this->plainTextKey])
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [[
                'scopeLogs' => [[
                    'logRecords' => [
                        ['body' => ['stringValue' => 'a'], 'severityNumber' => 9],
                        ['body' => ['stringValue' => 'b'], 'severityText' => 'warn'],
                        ['body' => ['stringValue' => 'c'], 'severityText' => 'fatal'],
                    ],
                ]],
            ]],
        ])->assertOk();

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        return $rows[0]['SeverityNumber'] === 9 && $rows[0]['SeverityText'] === 'INFO'
            && $rows[1]['SeverityNumber'] === 13 && $rows[1]['SeverityText'] === 'warn'
            && $rows[2]['SeverityNumber'] === 21 && $rows[2]['SeverityText'] === 'fatal';
    });
});

test('a complex otlp body is json encoded and missing timestamps fall back to now', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [[
                'scopeLogs' => [[
                    'logRecords' => [[
                        'body' => ['kvlistValue' => ['values' => [
                            ['key' => 'event', 'value' => ['stringValue' => 'checkout']],
                        ]]],
                    ]],
                ]],
            ]],
        ])->assertOk();

    Http::assertSent(function (Request $request) {
        $row = insertedRows($request)[0];

        // With no event time and no observed time, the row falls back to now:
        // there is no ObservedTimestamp column left to compare against.
        return $row['Body'] === '{"event":"checkout"}'
            && $row['EventName'] === ''
            && $row['ResourceSchemaUrl'] === ''
            && $row['ScopeSchemaUrl'] === ''
            && $row['ScopeAttributes'] === []
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{9}$/', $row['Timestamp']) === 1;
    });
});

test('numeric attribute keys still reach ClickHouse as JSON objects', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [[
                'resource' => ['attributes' => [['key' => '0', 'value' => ['stringValue' => 'r']]]],
                'scopeLogs' => [[
                    'scope' => ['attributes' => [['key' => '1', 'value' => ['stringValue' => 's']]]],
                    'logRecords' => [[
                        'body' => ['stringValue' => 'hello'],
                        'attributes' => [['key' => '0', 'value' => ['stringValue' => 'x']]],
                    ]],
                ]],
            ]],
        ])->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        // PHP stores "0" as an int key and json_encode would write ["x"]; the
        // writer casts every Map to an object so ClickHouse gets {"0":"x"}.
        expect($body)->toContain('"ResourceAttributes":{"0":"r"}')
            ->and($body)->toContain('"ScopeAttributes":{"1":"s"}')
            ->and($body)->toContain('"LogAttributes":{"0":"x"}');

        return true;
    });
});

test('a record whose supplied timestamps cannot be stored is rejected, never re-dated or 400', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [
                ['timeUnixNano' => str_repeat('9', 30), 'observedTimeUnixNano' => '1735689600', 'body' => ['stringValue' => 'bad clock']],
                ['timeUnixNano' => str_repeat('9', 30), 'observedTimeUnixNano' => '1735689600500000000', 'body' => ['stringValue' => 'observed wins']],
            ]]]]],
        ])
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedLogRecords', 1);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        return count($rows) === 1
            && $rows[0]['Body'] === 'observed wins'
            && $rows[0]['Timestamp'] === '2025-01-01 00:00:00.500000000';
    });
});

test('trace and span ids are stored the way the trace mapper stores them', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [
                ['traceId' => '5B8EFFF798038103D269B633813FC60C', 'spanId' => base64_encode(hex2bin('eee19b7ec3c1b174')), 'body' => ['stringValue' => 'linked']],
                ['traceId' => 'request-4821', 'spanId' => '', 'body' => ['stringValue' => 'kept raw']],
            ]]]]],
        ])->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        return $rows[0]['TraceId'] === '5b8efff798038103d269b633813fc60c'
            && $rows[0]['SpanId'] === 'eee19b7ec3c1b174'
            && $rows[1]['TraceId'] === 'request-4821'
            && $rows[1]['SpanId'] === '';
    });
});

test('malformed otlp records are skipped and reported as a partial success', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [
                'nonsense',
                [
                    'scopeLogs' => [[
                        'logRecords' => [
                            'also nonsense',
                            ['body' => ['stringValue' => 'kept']],
                        ],
                    ]],
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('partialSuccess.rejectedLogRecords', 2);

    Http::assertSent(fn (Request $request) => count(insertedRows($request)) === 1);
});

test('an unparseable otlp body is accepted without a client error', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->call(
        'POST',
        '/api/v1/logs',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainTextKey,
        ],
        content: '{not json at all',
    );

    $response->assertOk()->assertJsonPath('partialSuccess.rejectedLogRecords', 0);

    Http::assertNothingSent();
});

test('the protobuf encoding is rejected with a 415 and a json hint while the decoder is off', function () {
    // Protobuf is decoded in-process by default now; this is the answer an
    // instance that has switched it back off still gives.
    config(['bilis.ingest.otlp_protobuf' => false]);

    Http::fake();

    $response = $this->call(
        'POST',
        '/api/v1/logs',
        server: [
            'CONTENT_TYPE' => 'application/x-protobuf',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainTextKey,
        ],
        content: 'binary-protobuf-payload',
    );

    $response->assertStatus(415)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'JSON'));

    Http::assertNothingSent();
});

test('a missing or invalid api key is rejected with a 401', function () {
    Http::fake();

    $this->postJson('/api/v1/logs', ['resourceLogs' => []])->assertUnauthorized();
    $this->withToken('bilis_nope')->postJson('/api/v1/logs', ['resourceLogs' => []])->assertUnauthorized();

    Http::assertNothingSent();
});

test('a clickhouse overload becomes a 503 with a retry after header', function () {
    $this->mock(ClickHouseClient::class)
        ->shouldReceive('insert')
        ->once()
        ->andThrow(new ClickHouseException('overloaded', statusCode: 503));

    $response = $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [['body' => ['stringValue' => 'boom']]]]]]],
        ]);

    $response->assertStatus(503)->assertHeader('Retry-After', '5');
});

test('any other clickhouse failure also becomes a 503 rather than blaming the client', function () {
    $this->mock(ClickHouseClient::class)
        ->shouldReceive('insert')
        ->once()
        ->andThrow(new ClickHouseException('syntax error', statusCode: 400, errorCode: 62));

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [['body' => ['stringValue' => 'boom']]]]]]],
        ])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

test('trace flags are read from the proto field name that real exporters send', function (string $field) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/logs', [
            'resourceLogs' => [['scopeLogs' => [['logRecords' => [[
                'body' => ['stringValue' => 'sampled'],
                $field => 1,
            ]]]]]],
        ])
        ->assertOk();

    Http::assertSent(function (Request $request) {
        expect(insertedRows($request)[0]['TraceFlags'])->toBe(1);

        return true;
    });
})->with([
    // `flags` is the OTLP/JSON spelling — the proto field is LogRecord.flags,
    // and it is what a Collector and the protobuf decoder both produce.
    'flags',
    'traceFlags',
    'trace_flags',
]);
