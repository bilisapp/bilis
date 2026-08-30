<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\SpanWriter;
use App\Services\Traces\TraceFilters;
use App\Services\Traces\TraceQuery;
use Illuminate\Support\Carbon;

/*
 * The only test that exercises trace_summary against a real ClickHouse, and the
 * only one that can catch what SCHEMA.md R11 is about: the summary table's
 * failure modes are all silent, and all of them need more than one insert block
 * to show up. Http::fake cannot reproduce a materialized view.
 *
 * Skipped unless a server is reachable, so the suite still runs on a machine
 * without one. Point it at a throwaway database, not production.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse reachable; set CLICKHOUSE_* to run the trace summary integration test.');
    }

    // A project id of its own, so the test neither sees nor disturbs dev data.
    $this->projectId = 'test-'.bin2hex(random_bytes(6));
    $this->traceId = bin2hex(random_bytes(16));
    $this->client = $client;
});

afterEach(function () {
    if (! isset($this->client)) {
        return;
    }

    foreach (['otel_traces', 'trace_summary'] as $table) {
        $this->client->execute(sprintf(
            "ALTER TABLE %s DELETE WHERE ProjectId = '%s'",
            $table,
            $this->projectId,
        ));
    }
});

/**
 * One span row, already in the shape the mapper produces.
 *
 * @return array<string, mixed>
 */
function spanRow(string $projectId, string $traceId, string $spanId, string $parentSpanId, string $name, string $service, string $status, string $timestamp, int $durationNs = 1_000_000): array
{
    return [
        'Timestamp' => $timestamp,
        'TraceId' => $traceId,
        'SpanId' => $spanId,
        'ParentSpanId' => $parentSpanId,
        'TraceState' => '',
        'SpanName' => $name,
        'SpanKind' => 'Server',
        'ServiceName' => $service,
        'ResourceAttributes' => [],
        'ScopeName' => '',
        'ScopeVersion' => '',
        'SpanAttributes' => [],
        'Duration' => $durationNs,
        'StatusCode' => $status,
        'StatusMessage' => '',
        'Events.Timestamp' => [],
        'Events.Name' => [],
        'Events.Attributes' => [],
        'Links.TraceId' => [],
        'Links.SpanId' => [],
        'Links.TraceState' => [],
        'Links.Attributes' => [],
        'ProjectId' => $projectId,
    ];
}

/**
 * Insert one row as its own block, synchronously.
 *
 * `SpanWriter` uses `async_insert=1`, which coalesces inserts arriving close
 * together into a single block — helpful in production, and precisely what this
 * test must not have: the summary's failure modes only appear when a trace's
 * spans land in SEVERAL blocks. Writing them synchronously forces that, so the
 * test asserts the thing it claims to. The realistic path through SpanWriter is
 * covered by the tests below.
 *
 * @param  array<string, mixed>  $row
 */
function insertBlock(ClickHouseClient $client, array $row): void
{
    // Through SpanWriter::normalise, not around it: an empty Map has to reach
    // ClickHouse as {} rather than [], and a synchronous insert is the one place
    // that says so out loud instead of dropping the row after the ack.
    $normalised = SpanWriter::normalise([$row])[0];

    $client->execute(
        'INSERT INTO otel_traces FORMAT JSONEachRow '.json_encode($normalised, JSON_THROW_ON_ERROR),
    );
}

/**
 * Wait for the asynchronous insert to land, then run the caller's query.
 *
 * Inserts are queued (`wait_for_async_insert=0`), so "the row is not there yet"
 * and "the row is wrong" are different failures and only one of them is a bug.
 *
 * @param  callable(): array<int, array<string, mixed>>  $query
 * @return array<int, array<string, mixed>>
 */
function awaitRows(callable $query, int $expected): array
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = $query();

        if (count($rows) >= $expected) {
            return $rows;
        }

        usleep(100_000);
    }

    return $query();
}

/*
 * The test the whole engine choice exists for. One trace, three separate
 * inserts, so the materialized view fires three times and writes three summary
 * rows — and the middle insert is the only one carrying the root span.
 *
 * ReplacingMergeTree would keep the last row and lose Start. anyIf() instead of
 * max(if()) would let the root name come back empty. A reader without a GROUP BY
 * would see the trace three times with a SpanCount of 1. All three are silent.
 */
it('aggregates one trace correctly across three separate inserts', function () {
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('b', 16), str_repeat('a', 16), 'child-a', 'payments', 'Error', '2026-08-30 12:00:02.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'));
    // The last span starts at +5s and runs for 2s, so the trace ends at +7s.
    // This is the regression guard for End: `max(Timestamp)` would put the trace
    // end at the last span's START and report 5s, understating every trace by
    // however long its final span ran.
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('c', 16), str_repeat('a', 16), 'child-b', 'ledger', 'Unset', '2026-08-30 12:00:05.000000000', 2_000_000_000));

    // The summary really is several rows at this point: that is the condition
    // the assertions below are made under, not an accident.
    $raw = $this->client->select(
        'SELECT count() AS c FROM trace_summary WHERE ProjectId = {p:String}',
        ['p' => $this->projectId],
    );

    expect((int) $raw[0]['c'])->toBeGreaterThan(1);

    $result = app(TraceQuery::class)->list([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    ));

    expect($result['rows'])->toHaveCount(1);

    $trace = $result['rows'][0];

    expect($trace['traceId'])->toBe($this->traceId)
        // Start is the earliest span, not the first block inserted.
        ->and($trace['startedAt'])->toStartWith('2026-08-30 12:00:00')
        // The end of the last span, not its start.
        ->and($trace['endedAt'])->toStartWith('2026-08-30 12:00:07')
        ->and($trace['durationMs'])->toBe(7000.0)
        ->and($trace['spanCount'])->toBe(3)
        ->and($trace['errorCount'])->toBe(1)
        // The root arrived in the middle block; max(if(...)) has to prefer it
        // over the empty strings the other two contributed.
        ->and($trace['rootName'])->toBe('POST /pay')
        ->and($trace['rootService'])->toBe('checkout');
});

it('gives the same answer once the parts have merged', function () {
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('b', 16), str_repeat('a', 16), 'child', 'payments', 'Error', '2026-08-30 12:00:02.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'));

    $filters = new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    );

    $before = app(TraceQuery::class)->list([$this->projectId], $filters);

    $this->client->execute('OPTIMIZE TABLE trace_summary FINAL');

    $after = app(TraceQuery::class)->list([$this->projectId], $filters);

    expect($after['rows'])->toBe($before['rows'])
        ->and($after['rows'][0]['spanCount'])->toBe(2)
        ->and($after['rows'][0]['rootName'])->toBe('POST /pay');
});

it('reads back a span with its events and links intact', function () {
    $row = spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Error', '2026-08-30 12:00:00.000000000');
    $row['Events.Timestamp'] = ['2026-08-30 12:00:00.500000000', '2026-08-30 12:00:00.700000000'];
    $row['Events.Name'] = ['exception', 'retrying'];
    // The second event has no attributes: the Array(Map) element that has to
    // serialize as {} or the whole row is dropped after the ack.
    $row['Events.Attributes'] = [['exception.type' => 'RuntimeException'], []];

    app(SpanWriter::class)->write([$row]);

    awaitRows(fn () => $this->client->select(
        'SELECT SpanId FROM otel_traces WHERE ProjectId = {p:String}',
        ['p' => $this->projectId],
    ), 1);

    $result = app(TraceQuery::class)->spans(
        [$this->projectId],
        $this->traceId,
        Carbon::parse('2026-08-30 12:00:00', 'UTC'),
    );

    expect($result['spans'])->toHaveCount(1)
        ->and($result['truncated'])->toBeFalse();

    $span = $result['spans'][0];

    expect($span['name'])->toBe('POST /pay')
        ->and($span['statusCode'])->toBe('Error')
        ->and($span['events'])->toHaveCount(2)
        ->and($span['events'][0]['name'])->toBe('exception')
        ->and($span['events'][0]['attributes'])->toBe(['exception.type' => 'RuntimeException'])
        ->and($span['events'][1]['attributes'])->toBe([]);
});

it('finds a trace by id alone, through the summary table', function () {
    app(SpanWriter::class)->write([
        spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'),
    ]);

    awaitRows(fn () => $this->client->select(
        'SELECT SpanId FROM otel_traces WHERE ProjectId = {p:String}',
        ['p' => $this->projectId],
    ), 1);

    $found = app(TraceQuery::class)->summary([$this->projectId], $this->traceId);

    expect($found['unavailable'])->toBeFalse()
        ->and($found['trace'])->not->toBeNull()
        ->and($found['trace']['rootName'])->toBe('POST /pay')
        ->and($found['trace']['spanCount'])->toBe(1);

    // A trace that is genuinely absent, as distinct from storage being busy.
    $missing = app(TraceQuery::class)->summary([$this->projectId], str_repeat('f', 32));

    expect($missing['trace'])->toBeNull()
        ->and($missing['unavailable'])->toBeFalse();
});

/*
 * Every filter, against a real server. These are `HAVING` clauses over aggregates,
 * and an earlier version of the list query aliased `sum(ErrorCount) AS ErrorCount`
 * — which makes ClickHouse resolve the `sum(ErrorCount)` in HAVING to
 * `sum(sum(ErrorCount))` and fail with ILLEGAL_AGGREGATION. Http::fake never
 * executes the SQL, so only a live query can catch it. Same trap for RootService.
 */
it('applies every post-aggregation filter without an aggregation error', function () {
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Error', '2026-08-30 12:00:00.000000000', 400_000_000));

    $window = [
        'from' => Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        'to' => Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    ];

    $query = app(TraceQuery::class);

    $matching = [
        'errors only' => new TraceFilters(errorsOnly: true, from: $window['from'], to: $window['to']),
        'min duration' => new TraceFilters(minDurationMs: 100, from: $window['from'], to: $window['to']),
        'root service' => new TraceFilters(service: 'checkout', from: $window['from'], to: $window['to']),
        'all three' => new TraceFilters(service: 'checkout', errorsOnly: true, minDurationMs: 100, from: $window['from'], to: $window['to']),
    ];

    foreach ($matching as $label => $filters) {
        expect($query->list([$this->projectId], $filters)['rows'])
            ->toHaveCount(1, "the [{$label}] filter should have matched the seeded trace");
    }

    $excluding = [
        'min duration above the trace' => new TraceFilters(minDurationMs: 5000, from: $window['from'], to: $window['to']),
        'a different root service' => new TraceFilters(service: 'ledger', from: $window['from'], to: $window['to']),
    ];

    foreach ($excluding as $label => $filters) {
        expect($query->list([$this->projectId], $filters)['rows'])
            ->toHaveCount(0, "the [{$label}] filter should have excluded the seeded trace");
    }
});
