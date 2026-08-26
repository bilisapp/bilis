<?php

namespace App\Services\Ingest;

use App\Services\ClickHouse\ClickHouseClient;
use stdClass;

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

    /**
     * Columns with a ClickHouse Map type. JSONEachRow requires them to be
     * JSON objects; an empty PHP array would encode as `[]` and the whole
     * row would be discarded server-side after the async-insert ack.
     */
    private const MAP_COLUMNS = [
        'ResourceAttributes',
        'ScopeAttributes',
        'LogAttributes',
    ];

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Queue the given rows for insertion.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        foreach ($rows as &$row) {
            foreach (self::MAP_COLUMNS as $column) {
                if (($row[$column] ?? null) === []) {
                    $row[$column] = new stdClass;
                }
            }
        }
        unset($row);

        $this->client->insert(self::TABLE, $rows);
    }
}
