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
            // The <26.2 branch of SCHEMA.md R5: the index expression has to be
            // the same lower(Body) the search query uses.
            && str_contains($request->body(), 'INDEX idx_lower_body lower(Body) TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 8')
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

    Http::assertSentCount(2);
});

test('the migrate command may be run repeatedly', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->artisan('clickhouse:migrate')->assertSuccessful();
    $this->artisan('clickhouse:migrate')->assertSuccessful();

    Http::assertSentCount(4);
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
