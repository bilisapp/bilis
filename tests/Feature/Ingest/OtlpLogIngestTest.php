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
                'scopeLogs' => [[
                    'scope' => ['name' => 'bilis.instrumentation', 'version' => '1.2.3'],
                    'logRecords' => [[
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
                'ProjectId' => $this->project->id,
                'Timestamp' => '2025-01-01 00:00:00.123456789',
                'ObservedTimestamp' => '2025-01-01 00:00:00.987654321',
                'TraceId' => '5b8efff798038103d269b633813fc60c',
                'SpanId' => 'eee19b7ec3c1b174',
                'TraceFlags' => 1,
                'SeverityNumber' => 17,
                'SeverityText' => 'ERROR',
                'ServiceName' => 'checkout',
                'Body' => 'Payment failed',
                'ScopeName' => 'bilis.instrumentation',
                'ScopeVersion' => '1.2.3',
            ])
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

        return $row['Body'] === '{"event":"checkout"}'
            && $row['Timestamp'] === $row['ObservedTimestamp']
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{9}$/', $row['Timestamp']) === 1;
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

test('the protobuf encoding is rejected with a 415 and a json hint', function () {
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
