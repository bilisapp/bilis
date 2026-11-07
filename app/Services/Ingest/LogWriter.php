<?php

namespace App\Services\Ingest;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\Issues\ErrorFingerprinter;

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
     * Columns with a ClickHouse Map type.
     *
     * JSONEachRow requires a Map to be a JSON object, and a PHP array is not
     * reliably one: an empty array encodes as `[]`, and so does any array whose
     * keys happen to be `"0"`, `"1"`, … — PHP turns those into integers and
     * `json_encode` writes a list. Either way ClickHouse refuses the row *after*
     * the async insert was acked, so the whole block is lost silently. Every
     * Map column is therefore cast to an object unconditionally; see
     * {@see normalise()}.
     */
    private const MAP_COLUMNS = [
        'ResourceAttributes',
        'ScopeAttributes',
        'LogAttributes',
    ];

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly ErrorFingerprinter $fingerprinter,
    ) {}

    /**
     * Queue the given rows for insertion.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function write(array $rows): void
    {
        $this->client->insert(self::TABLE, self::normalise($this->stampIssueFingerprints($rows)));
    }

    /**
     * Set the IssueFingerprint column on every row.
     *
     * This is the one place it is written: the OTLP, simple and envelope paths
     * all insert through here, so a mapper never computes it and a payload can
     * never supply it. Rows that are not exceptions get '' explicitly, which
     * keeps them out of the issue materialized views. The value is the same
     * function Autofix runs over queried rows, so an issue and its fix jobs
     * share one identity.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function stampIssueFingerprints(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['IssueFingerprint'] = $this->fingerprinter->issueFingerprint($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * Make a row's Map columns serialize the way JSONEachRow requires.
     *
     * `(object) ['0' => 'x']` encodes as `{"0":"x"}` where the bare array would
     * be `["x"]`; `(object) []` is `{}`. Public and static for the same reason
     * {@see SpanWriter::normalise()} is: it is the only implementation of a
     * rule whose failure mode is a silent drop.
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
        }
        unset($row);

        return $rows;
    }
}
