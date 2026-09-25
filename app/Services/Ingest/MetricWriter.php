<?php

namespace App\Services\Ingest;

use App\Services\ClickHouse\ClickHouseClient;

/**
 * Writes mapped metric rows into the five `otel_metrics_*` tables.
 *
 * One insert per table that has rows. Inserts are asynchronous on the
 * ClickHouse side, so a successful write means queued, not durable. A failure
 * part way through leaves the tables already written holding their rows, and
 * the 503 makes the exporter retry the whole export — those rows are then
 * stored twice. That is the same exposure every retried batch has until inserts
 * carry a deduplication token (SCHEMA.md R7).
 */
class MetricWriter
{
    /**
     * Columns with a ClickHouse Map type; see {@see SpanWriter::MAP_COLUMNS}
     * for why every one is cast to an object unconditionally.
     */
    private const MAP_COLUMNS = [
        'ResourceAttributes',
        'ScopeAttributes',
        'Attributes',
    ];

    /**
     * Columns holding an Array(Map): the list stays a list, each element is
     * cast. Summary rows have no exemplars and simply lack the column.
     */
    private const MAP_ARRAY_COLUMNS = [
        'Exemplars.FilteredAttributes',
    ];

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Queue the given rows, one insert per table.
     *
     * @param  array<string, list<array<string, mixed>>>  $rowsByTable
     */
    public function write(array $rowsByTable): void
    {
        foreach ($rowsByTable as $table => $rows) {
            if ($rows !== []) {
                $this->client->insert($table, self::normalise($rows));
            }
        }
    }

    /**
     * Make a row's Map columns serialize the way JSONEachRow requires.
     *
     * Public and static for the same reason {@see SpanWriter::normalise()} is:
     * it is the only implementation of a rule that fails silently.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function normalise(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (self::MAP_COLUMNS as $column) {
                if (is_array($row[$column] ?? null)) {
                    $row[$column] = (object) $row[$column];
                }
            }

            foreach (self::MAP_ARRAY_COLUMNS as $column) {
                if (! is_array($row[$column] ?? null)) {
                    continue;
                }

                /** @var array<int, mixed> $maps */
                $maps = $row[$column];

                $row[$column] = array_map(
                    fn (mixed $map): mixed => is_array($map) ? (object) $map : $map,
                    $maps,
                );
            }
        }
        unset($row);

        return $rows;
    }
}
