<?php

namespace App\Services\Ingest;

use App\Services\ClickHouse\ClickHouseClient;

/**
 * Writes mapped log rows into ClickHouse.
 *
 * Inserts are asynchronous on the ClickHouse side, so a successful write means
 * the batch was queued rather than durably stored.
 */
class LogWriter
{
    /**
     * The table log records are stored in.
     */
    public const TABLE = 'otel_logs';

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Queue the given rows for insertion.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        $this->client->insert(self::TABLE, $rows);
    }
}
