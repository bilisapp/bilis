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

    foreach (['otel_traces', 'trace_summary', 'trace_index'] as $table) {
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

/*
 * The split-block trap, SCHEMA.md R13. A trace whose later spans land in a
 * second insert block has a second summary row with a later Start and an empty
 * root. A list or a tail whose boundary fell between the two blocks used to pass
 * only the late row through WHERE and aggregate a fragment — root name '', half
 * the spans, a 2-second duration for a 33-second trace — and the client,
 * replacing rows by trace id, overwrote the good row with it.
 *
 * insertBlock() is synchronous, so each call is its own block and its own
 * summary row without waiting for async flushes to separate them.
 */
it('aggregates every block of a trace whose blocks straddle the window boundary', function () {
    // Block one: the root and a first child, at 12:00:00 and 12:00:01.
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('b', 16), str_repeat('a', 16), 'child-a', 'payments', 'Unset', '2026-08-30 12:00:01.000000000'));
    // Block two, thirty seconds later: two more children, the last running 2s,
    // so the trace ends at 12:00:33.
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('c', 16), str_repeat('a', 16), 'child-b', 'ledger', 'Error', '2026-08-30 12:00:30.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('d', 16), str_repeat('a', 16), 'child-c', 'ledger', 'Unset', '2026-08-30 12:00:31.000000000', 2_000_000_000));

    // Several summary rows, several index rows: the condition under test.
    $summaryRows = $this->client->select('SELECT count() AS c FROM trace_summary WHERE ProjectId = {p:String}', ['p' => $this->projectId]);
    $indexRows = $this->client->select('SELECT count() AS c FROM trace_index WHERE ProjectId = {p:String}', ['p' => $this->projectId]);

    expect((int)$summaryRows[0]['c'])->toBeGreaterThan(1)
        ->and((int)$indexRows[0]['c'])->toBeGreaterThanOrEqual(1);

    $query = app(TraceQuery::class);

    $whole = fn(array $trace) => expect($trace['traceId'])->toBe($this->traceId)
        ->and($trace['rootName'])->toBe('POST /pay')
        ->and($trace['rootService'])->toBe('checkout')
        ->and($trace['startedAt'])->toStartWith('2026-08-30 12:00:00')
        ->and($trace['endedAt'])->toStartWith('2026-08-30 12:00:33')
        ->and($trace['durationMs'])->toBe(33000.0)
        ->and($trace['spanCount'])->toBe(4)
        ->and($trace['errorCount'])->toBe(1);

    // A window that holds the whole trace: one row, aggregated over both blocks.
    $list = $query->list([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:59:00', 'UTC'),
        to: Carbon::parse('2026-08-30 12:01:00', 'UTC'),
    ));

    expect($list['rows'])->toHaveCount(1);
    $whole($list['rows'][0]);

    // A window that opens BETWEEN the blocks: the trace started before it and
    // is not in it — never a fragment made of the late block alone.
    $between = $query->list([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 12:00:15', 'UTC'),
        to: Carbon::parse('2026-08-30 12:01:00', 'UTC'),
    ));

    expect($between['rows'])->toHaveCount(0);

    // A tail whose cursor falls between the blocks: the late block ended after
    // the cursor, so the trace is re-sent — whole, with the root the first block
    // carried, so the client's replace-by-id lands the full counts.
    $tail = $query->tail([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 12:00:15', 'UTC'),
    ), Carbon::parse('2026-08-30 12:00:25', 'UTC'));

    expect($tail['rows'])->toHaveCount(1);
    $whole($tail['rows'][0]);

    // A cursor past the trace's end: nothing has changed, nothing is re-sent.
    $quiet = $query->tail([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 12:01:00', 'UTC'),
    ), Carbon::parse('2026-08-30 12:01:00', 'UTC'));

    expect($quiet['rows'])->toHaveCount(0);
});

/*
 * The candidate table has to be fed on the realistic path too — SpanWriter's
 * asynchronous insert — or the list is empty for every trace that arrives after
 * the backfill.
 */
it('indexes a trace written through SpanWriter and lists it by the hour', function () {
    app(SpanWriter::class)->write([
        spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'),
    ]);

    $indexed = awaitRows(fn() => $this->client->select(
        'SELECT Hour FROM trace_index WHERE ProjectId = {p:String}',
        ['p' => $this->projectId],
    ), 1);

    expect($indexed)->toHaveCount(1)
        ->and((string)$indexed[0]['Hour'])->toBe('2026-08-30 12:00:00');

    $result = app(TraceQuery::class)->list([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    ));

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['rootName'])->toBe('POST /pay');
});

/*
 * A span read bounded by the trace's own extent, and the cap on it.
 */
it('reads a long trace whole between its summary bounds and cuts it at the cap', function () {
    // Root at 12:00, a child twenty minutes in — beyond the old +5 min bracket.
    app(SpanWriter::class)->write([
        spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'session', 'agent', 'Unset', '2026-08-30 12:00:00.000000000', 1_500_000_000_000),
        spanRow($this->projectId, $this->traceId, str_repeat('b', 16), str_repeat('a', 16), 'llm_request', 'agent', 'Unset', '2026-08-30 12:20:00.000000000'),
    ]);

    awaitRows(fn() => $this->client->select(
        'SELECT SpanId FROM otel_traces WHERE ProjectId = {p:String}',
        ['p' => $this->projectId],
    ), 2);

    $query = app(TraceQuery::class);

    $found = $query->summary([$this->projectId], $this->traceId);
    expect($found['trace'])->not->toBeNull();

    $whole = $query->spansBetween(
        [$this->projectId],
        $this->traceId,
        Carbon::parse($found['trace']['startedAt'], 'UTC')->subSecond(),
        Carbon::parse($found['trace']['endedAt'], 'UTC')->addSecond(),
    );

    expect($whole['spans'])->toHaveCount(2)
        ->and($whole['capped'])->toBeFalse()
        // Tree order, deterministic: the root first.
        ->and($whole['spans'][0]['spanId'])->toBe(str_repeat('a', 16));

    // The old bracket around the root would have missed the child.
    expect($query->spans([$this->projectId], $this->traceId, Carbon::parse('2026-08-30 12:00:00', 'UTC'))['spans'])
        ->toHaveCount(1);

    // Asked for a day, granted six hours, and told so.
    $capped = $query->spansBetween(
        [$this->projectId],
        $this->traceId,
        Carbon::parse('2026-08-30 12:00:00', 'UTC'),
        Carbon::parse('2026-08-31 12:00:00', 'UTC'),
    );

    expect($capped['capped'])->toBeTrue()
        ->and($capped['spans'])->toHaveCount(2);
});

/*
 * The histogram, live. It has to be R11-correct — one trace whose spans landed
 * in three blocks is ONE bar's worth, with ONE error, not three — and its HAVING
 * carries the same aliases the list's does, so it is exposed to the same
 * ILLEGAL_AGGREGATION trap that Http::fake can never see.
 */
it('buckets traces by their aggregated start and counts a split trace once', function () {
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('b', 16), str_repeat('a', 16), 'child-a', 'payments', 'Error', '2026-08-30 12:00:02.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'));
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('c', 16), str_repeat('a', 16), 'child-b', 'ledger', 'Unset', '2026-08-30 12:00:05.000000000', 2_000_000_000));

    // A second, clean trace twenty minutes later, in a bucket of its own.
    $other = bin2hex(random_bytes(16));
    insertBlock($this->client, spanRow($this->projectId, $other, str_repeat('d', 16), '', 'GET /orders', 'checkout', 'Unset', '2026-08-30 12:20:00.000000000'));

    $query = app(TraceQuery::class);

    $window = [
        'from' => Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        'to' => Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    ];

    $histogram = $query->histogram([$this->projectId], new TraceFilters(from: $window['from'], to: $window['to']));

    // Two hours on the ladder is 300-second bars: 24 plus the closing edge.
    expect($histogram['unavailable'])->toBeFalse()
        ->and($histogram['intervalSeconds'])->toBe(300)
        ->and($histogram['buckets'])->toHaveCount(25)
        ->and($histogram['total'])->toBe(2)
        ->and($histogram['errors'])->toBe(1);

    $byBucket = collect($histogram['buckets'])->keyBy('at');

    // Three blocks, one trace, one failed trace — in the bucket of its root's
    // start, not of the block that happened to arrive first.
    expect($byBucket['2026-08-30 12:00:00.000000'])->toMatchArray(['traces' => 1, 'errors' => 1])
        ->and($byBucket['2026-08-30 12:20:00.000000'])->toMatchArray(['traces' => 1, 'errors' => 0])
        ->and($byBucket['2026-08-30 12:05:00.000000'])->toMatchArray(['traces' => 0, 'errors' => 0]);

    // The post-aggregation filters apply here exactly as they do on the list.
    $failedOnly = $query->histogram([$this->projectId], new TraceFilters(errorsOnly: true, from: $window['from'], to: $window['to']));

    expect($failedOnly['total'])->toBe(1)
        ->and($failedOnly['errors'])->toBe(1);

    $ledgerRooted = $query->histogram([$this->projectId], new TraceFilters(service: 'ledger', from: $window['from'], to: $window['to']));

    expect($ledgerRooted['total'])->toBe(0);
});

/*
 * The service picker, live. A block that carried no root contributes an empty
 * RootService to trace_summary; the list has to drop it rather than offer '' as
 * a service, and the DISTINCT has to run against the index-nominated ids.
 */
it('lists the distinct root services and never an empty one', function () {
    insertBlock($this->client, spanRow($this->projectId, $this->traceId, str_repeat('a', 16), '', 'POST /pay', 'checkout', 'Unset', '2026-08-30 12:00:00.000000000'));

    // A trace whose only stored block is a child: its summary row has no root.
    $rootless = bin2hex(random_bytes(16));
    insertBlock($this->client, spanRow($this->projectId, $rootless, str_repeat('b', 16), str_repeat('z', 16), 'child', 'payments', 'Unset', '2026-08-30 12:01:00.000000000'));

    $third = bin2hex(random_bytes(16));
    insertBlock($this->client, spanRow($this->projectId, $third, str_repeat('c', 16), '', 'ledger.settle', 'ledger', 'Unset', '2026-08-30 12:02:00.000000000'));

    $services = app(TraceQuery::class)->services([$this->projectId], new TraceFilters(
        from: Carbon::parse('2026-08-30 11:00:00', 'UTC'),
        to: Carbon::parse('2026-08-30 13:00:00', 'UTC'),
    ));

    expect($services)->toBe(['checkout', 'ledger']);
});
