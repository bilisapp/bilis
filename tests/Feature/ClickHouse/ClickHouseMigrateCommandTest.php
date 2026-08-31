<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'clickhouse.scheme' => 'http',
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
        'clickhouse.username' => 'default',
        'clickhouse.password' => '',
    ]);
});

test('the migrate command creates the database and the otel logs table', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->body() === 'CREATE DATABASE IF NOT EXISTS `bilis`'
            && ! array_key_exists('database', $query);
    });

    Http::assertSent(function (Request $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'CREATE TABLE IF NOT EXISTS otel_logs')
            && str_contains($request->body(), 'ENGINE = MergeTree')
            && str_contains($request->body(), 'PARTITION BY toDate(Timestamp)')
            && str_contains($request->body(), 'ORDER BY (ProjectId, Timestamp, ServiceName)')
            && str_contains($request->body(), 'INDEX idx_service ServiceName TYPE set(100) GRANULARITY 4')
            && str_contains($request->body(), 'TTL toDateTime(Timestamp) + toIntervalDay(30)')
            && str_contains($request->body(), 'ttl_only_drop_parts = 1')
            && str_contains($request->body(), 'non_replicated_deduplication_window = 1000')
            // SCHEMA.md R5 on a >= 26.2 floor: a text index, still on lower(Body)
            // because the tokenizer does not fold case, and still character for
            // character the expression LogQuery searches against.
            && str_contains($request->body(), "INDEX idx_lower_body lower(Body) TYPE text(tokenizer = 'splitByNonAlpha') GRANULARITY 8")
            && ! str_contains($request->body(), 'tokenbf_v1')
            && str_contains($request->body(), 'INDEX idx_trace_id         TraceId                       TYPE bloom_filter(0.001) GRANULARITY 1')
            && str_contains($request->body(), 'INDEX idx_scope_attr_key   mapKeys(ScopeAttributes)      TYPE bloom_filter(0.01)  GRANULARITY 1')
            && str_contains($request->body(), 'ResourceSchemaUrl  LowCardinality(String)              CODEC(ZSTD(1))')
            && str_contains($request->body(), 'ScopeAttributes    Map(LowCardinality(String), String) CODEC(ZSTD(1))')
            && str_contains($request->body(), 'EventName          String                              CODEC(ZSTD(1))')
            && str_contains($request->body(), "ProjectId          LowCardinality(String) DEFAULT ''   CODEC(ZSTD(1))")
            // R6: no derived timestamp columns may come back.
            && ! str_contains($request->body(), 'ObservedTimestamp')
            && ! str_contains($request->body(), 'TimestampTime')
            && ! str_ends_with($request->body(), ';')
            && ($query['database'] ?? null) === 'bilis';
    });

    // The database plus nine schema files.
    Http::assertSentCount(10);
});

test('the migrate command may be run repeatedly', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();
    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSentCount(20);
});

test('the migrate command fails when clickhouse rejects the schema', function () {
    Http::fake([
        '127.0.0.1:8123/*' => Http::response(
            'Code: 62. DB::Exception: Syntax error',
            500,
            ['X-ClickHouse-Exception-Code' => '62'],
        ),
    ]);

    $this->artisan('clickhouse:migrate')->assertFailed();
});

test('the migrate command creates the traces table, the summary table and its view', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        return str_contains($body, 'CREATE TABLE IF NOT EXISTS otel_traces')
            // R1: the column list is the exporter's, ScopeName/ScopeVersion
            // included — a stock exporter INSERT names them.
            && str_contains($body, 'ScopeName           String                              CODEC(ZSTD(1))')
            && str_contains($body, 'ScopeVersion        String                              CODEC(ZSTD(1))')
            && str_contains($body, 'Duration            UInt64                              CODEC(ZSTD(1))')
            && str_contains($body, 'Events Nested (')
            // The event tick carries the same explicit zone as the span
            // Timestamp: a naive DateTime64 renders in the SERVER zone.
            && str_contains($body, "Timestamp  DateTime64(9, 'UTC'),")
            && str_contains($body, 'Links Nested (')
            // Ours: same sort key as the logs table.
            && str_contains($body, 'ORDER BY (ProjectId, Timestamp, ServiceName)')
            && str_contains($body, 'TTL toDateTime(Timestamp) + toIntervalDay(30)');
    });

    Http::assertSent(function (Request $request) {
        // The negative assertions below have to read the statement rather than
        // the file, because the comments explaining each prohibition name the
        // very thing they forbid.
        $statement = clickHouseStatement($request);

        // R11: the three write-side choices that fail silently if changed.
        return str_contains($statement, 'CREATE TABLE IF NOT EXISTS trace_summary')
            && str_contains($statement, 'ENGINE = AggregatingMergeTree')
            && ! str_contains($statement, 'ReplacingMergeTree')
            && ! str_contains($statement, 'PARTITION BY')
            && str_contains($statement, 'TTL toDateTime(Start) + toIntervalDay(90)');
    });

    Http::assertSent(function (Request $request) {
        $statement = clickHouseStatement($request);

        // R10: the exporter writes 'Error', not the proto enum name. R11: the
        // root columns use max(if(...)), never anyIf(...).
        return str_contains($statement, 'CREATE MATERIALIZED VIEW IF NOT EXISTS trace_summary_mv TO trace_summary')
            && str_contains($statement, "countIf(StatusCode = 'Error')")
            && ! str_contains($statement, 'STATUS_CODE_ERROR')
            && str_contains($statement, "max(if(ParentSpanId = '', SpanName, ''))")
            && ! str_contains($statement, 'anyIf(');
    });
});

test('the migrate command migrates a deployed logs table onto the text body index', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        /*
         * A fresh database gets the text index from the CREATE in 0001; a
         * deployed one can only get it here, because CREATE TABLE IF NOT EXISTS
         * cannot alter a table that already exists. Both clauses are guarded so
         * the statement is a no-op in either direction — which it has to be,
         * since docker-entrypoint.sh runs migrate once per container role and a
         * deploy can issue this three times at once.
         */
        return str_contains($body, 'ALTER TABLE otel_logs')
            && str_contains($body, 'DROP INDEX IF EXISTS idx_lower_body')
            && str_contains($body, "ADD INDEX IF NOT EXISTS idx_lower_body lower(Body) TYPE text(tokenizer = 'splitByNonAlpha') GRANULARITY 8");
    });
});

test('the migrate command converges a deployed traces table onto a zoned event timestamp', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $statement = clickHouseStatement($request);

        /*
         * 0002 declares Events.Timestamp as DateTime64(9, 'UTC') for a fresh
         * install; a deployed table shipped with the naive type and can only be
         * converged here. Metadata only, and guarded so the per-role re-runs
         * from docker-entrypoint.sh are no-ops.
         */
        return str_contains($statement, 'ALTER TABLE otel_traces')
            && str_contains($statement, "MODIFY COLUMN IF EXISTS `Events.Timestamp` Array(DateTime64(9, 'UTC'))");
    });
});

/*
 * SCHEMA.md R13: the trace list's time index, its view, and the one-off
 * backfill that re-runs on every boot and has to decide for itself.
 */
test('the migrate command creates the trace index, its view and the guarded backfill', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSent(function (Request $request) {
        $statement = clickHouseStatement($request);

        return str_contains($statement, 'CREATE TABLE IF NOT EXISTS trace_index')
            && str_contains($statement, 'ENGINE = AggregatingMergeTree')
            // Keyed by the hour, which is what the summary table is not.
            && str_contains($statement, 'ORDER BY (ProjectId, Hour, TraceId)')
            // Hour is part of the key, so unlike trace_summary this table may
            // be partitioned: a merge cannot move a row's hour.
            && str_contains($statement, 'PARTITION BY toDate(Hour)')
            // Same retention as the summary it nominates candidates from.
            && str_contains($statement, 'TTL Hour + toIntervalDay(90)');
    });

    Http::assertSent(function (Request $request) {
        $statement = clickHouseStatement($request);

        return str_contains($statement, 'CREATE MATERIALIZED VIEW IF NOT EXISTS trace_index_mv TO trace_index')
            && str_contains($statement, 'toStartOfHour(min(Timestamp)) AS Hour')
            // End is the last span's END, as in trace_summary_mv.
            && str_contains($statement, 'max(Timestamp + toIntervalNanosecond(Duration)) AS End');
    });

    Http::assertSent(function (Request $request) {
        $statement = clickHouseStatement($request);

        /*
         * From the summary, which outlives the spans; guarded on the index's
         * earliest hour (cheap) rather than on emptiness alone, because a
         * rolling deploy feeds the new view before this statement runs, and
         * never on a distinct count of every trace pair, which would hash the
         * whole history into memory on every boot; and aliased away from the
         * column names, because `min(Start) AS Start` is ILLEGAL_AGGREGATION.
         */
        return str_contains($statement, 'INSERT INTO trace_index (ProjectId, Hour, TraceId, Start, End)')
            && str_contains($statement, 'FROM trace_summary')
            && str_contains($statement, '(SELECT count() FROM trace_index) = 0')
            && str_contains($statement, 'OR (SELECT min(Hour) FROM trace_index)')
            && str_contains($statement, '> (SELECT toStartOfHour(min(Start)) FROM trace_summary WHERE Start >= now() - toIntervalDay(89))')
            && !str_contains($statement, 'uniqExact')
            && !str_contains($statement, 'AS Start')
            && str_contains($statement, 'GROUP BY ProjectId, TraceId');
    });
});
