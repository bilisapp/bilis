<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    $this->plainTextKey = 'bilis_'.str_repeat('b', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();
});

test('a single simple log record is accepted and mapped', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->withToken($this->plainTextKey)->postJson('/api/v1/ingest', [
        'timestamp' => '2025-01-01T00:00:00.123456Z',
        'level' => 'error',
        'message' => 'Something broke',
        'service' => 'billing',
        'trace_id' => '5b8efff798038103d269b633813fc60c',
        'context' => ['user_id' => 7, 'retry' => true, 'tags' => ['a']],
    ]);

    $response->assertStatus(202)->assertExactJson(['accepted' => 1, 'skipped' => 0]);

    Http::assertSent(function (Request $request) {
        $row = insertedRows($request)[0];

        expect($row)->toMatchArray([
            // ProjectId is a String column, written from the authenticated key.
            'ProjectId' => (string) $this->project->id,
            'Timestamp' => '2025-01-01 00:00:00.123456000',
            'TraceId' => '5b8efff798038103d269b633813fc60c',
            'SeverityNumber' => 17,
            'SeverityText' => 'error',
            'ServiceName' => 'billing',
            'Body' => 'Something broke',
            // The simple format has no schema urls, scope attributes or event
            // name, but the columns are still written explicitly (R1).
            'ResourceSchemaUrl' => '',
            'ScopeSchemaUrl' => '',
            'EventName' => '',
        ])->and($row['LogAttributes'])->toBe([
            'user_id' => '7',
            'retry' => 'true',
            'tags' => '["a"]',
        ])->and($row['ScopeAttributes'])->toBe([])
            ->and($row)->not->toHaveKey('ObservedTimestamp');

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{9}$/', $row['Timestamp']) === 1;
    });
});

test('a batch of simple log records is accepted', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)->postJson('/api/v1/ingest', [
        ['message' => 'first', 'level' => 'info', 'timestamp' => 1735689600],
        ['message' => 'second', 'level' => 'debug', 'timestamp' => 1735689600123],
        ['body' => 'third'],
    ])->assertStatus(202)->assertExactJson(['accepted' => 3, 'skipped' => 0]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        return count($rows) === 3
            && $rows[0]['Timestamp'] === '2025-01-01 00:00:00.000000000'
            && $rows[0]['SeverityNumber'] === 9
            && $rows[1]['Timestamp'] === '2025-01-01 00:00:00.123000000'
            && $rows[1]['SeverityNumber'] === 5
            && $rows[2]['Body'] === 'third'
            && $rows[2]['SeverityNumber'] === 0;
    });
});

test('records without a message are skipped without failing the request', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)->postJson('/api/v1/ingest', [
        ['message' => 'kept'],
        ['level' => 'error'],
        'not an object',
    ])->assertStatus(202)->assertExactJson(['accepted' => 1, 'skipped' => 2]);

    Http::assertSent(fn (Request $request) => count(insertedRows($request)) === 1);
});

test('an unparseable body is accepted with zero counted records', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = $this->call(
        'POST',
        '/api/v1/ingest',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainTextKey,
        ],
        content: 'this is not json',
    );

    $response->assertStatus(202)
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('skipped', 1);

    Http::assertNothingSent();
});

test('a missing or invalid api key is rejected with a 401', function () {
    Http::fake();

    $this->postJson('/api/v1/ingest', ['message' => 'hi'])->assertUnauthorized();
    $this->withHeaders(['X-Bilis-Key' => 'bilis_nope'])
        ->postJson('/api/v1/ingest', ['message' => 'hi'])
        ->assertUnauthorized();

    Http::assertNothingSent();
});

test('a clickhouse overload becomes a 503 with a retry after header', function () {
    $this->mock(ClickHouseClient::class)
        ->shouldReceive('insert')
        ->once()
        ->andThrow(new ClickHouseException('overloaded', connectionFailed: true));

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/ingest', ['message' => 'boom'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

test('empty attribute maps are serialized as JSON objects, not arrays', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/ingest', ['message' => 'no context at all'])
        ->assertStatus(202);

    Http::assertSent(function (Request $request) {
        $line = trim($request->body());

        // ClickHouse JSONEachRow rejects `[]` for Map columns and, with
        // wait_for_async_insert=0, drops the row silently after the ack.
        return str_contains($line, '"ResourceAttributes":{}')
            && str_contains($line, '"ScopeAttributes":{}')
            && str_contains($line, '"LogAttributes":{}')
            && ! str_contains($line, '":[]');
    });
});

test('numeric context keys still reach ClickHouse as JSON objects', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/ingest', ['message' => 'hello', 'context' => ['0' => 'x', 'order' => '42']])
        ->assertStatus(202);

    Http::assertSent(fn(Request $request) => str_contains($request->body(), '"LogAttributes":{"0":"x","order":"42"}'));
});

test('trace and span ids are normalised like the trace mapper, and kept raw when unreadable', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->withToken($this->plainTextKey)
        ->postJson('/api/v1/ingest', [
            ['message' => 'a', 'trace_id' => '5B8EFFF798038103D269B633813FC60C', 'span_id' => ' EEE19B7EC3C1B174 '],
            ['message' => 'b', 'trace_id' => 'request-4821', 'span_id' => str_repeat('0', 16)],
        ])
        ->assertStatus(202);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        return $rows[0]['TraceId'] === '5b8efff798038103d269b633813fc60c'
            && $rows[0]['SpanId'] === 'eee19b7ec3c1b174'
            && $rows[1]['TraceId'] === 'request-4821'
            && $rows[1]['SpanId'] === '';
    });
});
