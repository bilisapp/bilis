<?php

namespace App\Services\Ingest;

use App\Services\ClickHouse\ClickHouseClient;
use stdClass;

/**
 * Writes mapped span rows into ClickHouse.
 *
 * Inserts are asynchronous on the ClickHouse side, so a successful write means
 * the batch was queued rather than durably stored.
 */
class SpanWriter
{
    /**
     * The table spans are stored in.
     */
    public const TABLE = 'otel_traces';

    /**
     * Columns with a ClickHouse Map type.
     *
     * JSONEachRow requires a Map to be a JSON object; an empty PHP array encodes
     * as `[]` and the row is discarded server-side *after* the async-insert has
     * already been acked, so the loss is silent. Same trap as
     * {@see LogWriter::MAP_COLUMNS}.
     */
    private const MAP_COLUMNS = [
        'ResourceAttributes',
        'SpanAttributes',
    ];

    /**
     * Columns holding an Array(Map).
     *
     * The outer `[]` is correct and must stay a JSON array — it is a genuinely
     * empty list of events or links. It is each *element* that has to be `{}`,
     * which is the part easy to miss: a span carrying two events, neither with
     * attributes, would otherwise queue `[[], []]` and vanish.
     */
    private const MAP_ARRAY_COLUMNS = [
        'Events.Attributes',
        'Links.Attributes',
    ];

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Queue the given rows for insertion.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        $this->client->insert(self::TABLE, self::normalise($rows));
    }

    /**
     * Make a row's Map columns serialize the way JSONEachRow requires.
     *
     * Public and static because it is the only implementation of a rule that
     * fails silently: anything writing spans has to go through it, tests
     * included, or it is testing a shape ClickHouse would have thrown away.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function normalise(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (self::MAP_COLUMNS as $column) {
                if (($row[$column] ?? null) === []) {
                    $row[$column] = new stdClass;
                }
            }

            foreach (self::MAP_ARRAY_COLUMNS as $column) {
                if (! is_array($row[$column] ?? null)) {
                    continue;
                }

                /** @var array<int, mixed> $maps */
                $maps = $row[$column];

                $row[$column] = array_map(
                    fn (mixed $map): mixed => $map === [] ? new stdClass : $map,
                    $maps,
                );
            }
        }
        unset($row);

        return $rows;
    }
}
