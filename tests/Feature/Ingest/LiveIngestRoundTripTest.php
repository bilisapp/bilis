<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\OtlpLogMapper;
use App\Services\Ingest\OtlpTraceMapper;
use App\Services\Ingest\SpanWriter;

/*
 * The only test that can prove a mapped row SURVIVES ClickHouse. Every other
 * ingest test fakes the HTTP call, and a fake accepts `["x"]` for a Map column
 * as happily as `{"0":"x"}` — the real server acks the async insert and then
 * drops the block, and nothing in the request/response cycle says so.
 *
 * Skipped unless a server is reachable. Rows go under a throwaway ProjectId and
 * are deleted afterwards; nothing here touches dev data.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse reachable; set CLICKHOUSE_* to run the ingest round-trip test.');
    }

    $this->client = $client;
    $this->projectId = '9' . random_int(100000, 999999);
});

afterEach(function () {
    if (!isset($this->client)) {
        return;
    }

    foreach (['otel_traces', 'trace_summary', 'otel_logs'] as $table) {
        $this->client->execute(sprintf("ALTER TABLE %s DELETE WHERE ProjectId = '%s'", $table, $this->projectId));
    }
});

/**
 * Move every `*UnixNano` in a fixture to the present, keeping their spacing.
 *
 * The fixtures were captured in 2025 and the tables carry a 30-day TTL; a row
 * dated past it never comes out of the async flush (the same rows dated now do),
 * which is indistinguishable from the bug this file exists to catch.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function datedNow(array $payload, string $earliest): array
{
    $delta = ((int)(microtime(true) * 1_000) - 60_000) * 1_000_000 - (int)$earliest;

    array_walk_recursive($payload, function (mixed &$value, string|int $key) use ($delta): void {
        if (is_string($key) && str_ends_with($key, 'UnixNano') && is_string($value) && ctype_digit($value)) {
            $value = (string)((int)$value + $delta);
        }
    });

    return $payload;
}

/**
 * Poll until the async insert has landed, or give up after a few seconds.
 */
function awaitCount(ClickHouseClient $client, string $table, string $projectId, int $expected): int
{
    $count = 0;

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = $client->select(
            sprintf('SELECT count() AS c FROM %s WHERE ProjectId = {p:String}', $table),
            ['p' => $projectId],
        );

        $count = (int)$rows[0]['c'];

        if ($count >= $expected) {
            return $count;
        }

        usleep(100_000);
    }

    return $count;
}

it('stores every kitchen-sink span plus a numeric-keyed one and reads the maps back intact', function () {
    $payload = json_decode((string)file_get_contents(base_path('tests/Fixtures/otlp/otlp-traces-kitchen-sink.json')), true);
    $payload = datedNow($payload, '1756211400000000000');

    // The "0"-keyed span rides in the same insert block as the fixture: if the
    // writer got the cast wrong, ClickHouse would throw the whole block away and
    // the fixture spans would vanish with it.
    $start = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['startTimeUnixNano'];
    $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][] = [
        'traceId' => str_repeat('e', 32),
        'spanId' => str_repeat('f', 16),
        'name' => 'numeric-keys',
        'startTimeUnixNano' => $start,
        'endTimeUnixNano' => (string)((int)$start + 1_000_000),
        'attributes' => [['key' => '0', 'value' => ['stringValue' => 'x']]],
        'events' => [['name' => 'e', 'timeUnixNano' => (string)((int)$start + 500_000), 'attributes' => [['key' => '1', 'value' => ['stringValue' => 'y']]]]],
        'links' => [['traceId' => str_repeat('c', 32), 'spanId' => str_repeat('d', 16), 'attributes' => [['key' => '0', 'value' => ['stringValue' => 'z']]]]],
    ];

    $mapped = (new OtlpTraceMapper)->map($payload, $this->projectId);

    expect($mapped->rejected)->toBe(0)
        ->and($mapped->accepted())->toBeGreaterThan(1);

    app(SpanWriter::class)->write($mapped->rows);

    expect(awaitCount($this->client, 'otel_traces', $this->projectId, $mapped->accepted()))->toBe($mapped->accepted());

    $rows = $this->client->select(
        'SELECT SpanName, SpanAttributes, `Events.Attributes` AS EventAttributes, `Links.Attributes` AS LinkAttributes FROM otel_traces WHERE ProjectId = {p:String} AND SpanId = {s:String}',
        ['p' => $this->projectId, 's' => str_repeat('f', 16)],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['SpanName'])->toBe('numeric-keys')
        ->and($rows[0]['SpanAttributes'])->toBe(['0' => 'x'])
        ->and($rows[0]['EventAttributes'])->toBe([['1' => 'y']])
        ->and($rows[0]['LinkAttributes'])->toBe([['0' => 'z']]);

    // And the fixture's own maps came through the same block untouched.
    $fixture = $this->client->select(
        'SELECT SpanAttributes, ResourceAttributes FROM otel_traces WHERE ProjectId = {p:String} AND SpanId = {s:String}',
        ['p' => $this->projectId, 's' => 'eee19b7ec3c1b174'],
    );

    expect($fixture)->toHaveCount(1)
        ->and($fixture[0]['ResourceAttributes'])->toMatchArray(['service.name' => 'checkout'])
        ->and($fixture[0]['SpanAttributes'])->toMatchArray(['http.method' => 'POST']);
});

it('stores a numeric-keyed log record and reads its maps back intact', function () {
    $payload = json_decode((string)file_get_contents(base_path('tests/Fixtures/otlp/otlp-logs-kitchen-sink.json')), true);
    $payload = datedNow($payload, '1756211400000000000');

    $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][] = [
        'timeUnixNano' => (string)((int)(microtime(true) * 1_000) * 1_000_000),
        'body' => ['stringValue' => 'numeric-keys'],
        'attributes' => [['key' => '0', 'value' => ['stringValue' => 'x']]],
    ];

    $mapped = (new OtlpLogMapper)->map($payload, $this->projectId);

    expect($mapped->rejected)->toBe(0);

    app(LogWriter::class)->write($mapped->rows);

    expect(awaitCount($this->client, 'otel_logs', $this->projectId, $mapped->accepted()))->toBe($mapped->accepted());

    $rows = $this->client->select(
        'SELECT LogAttributes FROM otel_logs WHERE ProjectId = {p:String} AND Body = {b:String}',
        ['p' => $this->projectId, 'b' => 'numeric-keys'],
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['LogAttributes'])->toBe(['0' => 'x']);
});
