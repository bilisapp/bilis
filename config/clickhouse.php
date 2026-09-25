<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Connection
    |--------------------------------------------------------------------------
    |
    | Bilis talks to ClickHouse over its native HTTP interface, so no extra
    | driver or PHP extension is required. These values describe where the
    | server lives and which credentials should be used to reach it.
    |
    */

    'scheme' => env('CLICKHOUSE_SCHEME', 'http'),

    'host' => env('CLICKHOUSE_HOST', '127.0.0.1'),

    'port' => (int) env('CLICKHOUSE_PORT', 8123),

    'database' => env('CLICKHOUSE_DATABASE', 'bilis'),

    'username' => env('CLICKHOUSE_USERNAME', 'default'),

    'password' => env('CLICKHOUSE_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait for a complete response and, separately,
    | the number of seconds to wait while establishing the connection. Both
    | are kept short so an overloaded cluster fails fast for ingestion.
    |
    */

    'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 10),

    'connect_timeout' => (int) env('CLICKHOUSE_CONNECT_TIMEOUT', 3),

    // Schema statements (clickhouse:migrate, clickhouse:materialize-index)
    // wait longer: the trace_index backfill reads 90 days of trace_summary, and
    // a boot-time migrate that timed out mid-backfill stopped the container.
    'statement_timeout' => (int) env('CLICKHOUSE_STATEMENT_TIMEOUT', 600),

];
