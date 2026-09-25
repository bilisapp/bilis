<?php

namespace App\Services\Ingest;

/**
 * The result of mapping an OTLP metrics export into rows for the five
 * `otel_metrics_*` tables.
 *
 * The twin of {@see MappedSpans}, keyed by table because one export carries
 * every metric type at once. Rejections are counted in data points, which is
 * the unit OTLP's `rejectedDataPoints` reports.
 */
class MappedMetrics
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $rows  Rows per table name.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $rejected = 0,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * The number of data points that will be handed to ClickHouse.
     */
    public function accepted(): int
    {
        return array_sum(array_map(count(...), $this->rows));
    }

    /**
     * Whether any data point of the payload had to be dropped.
     */
    public function hasRejections(): bool
    {
        return $this->rejected > 0 || $this->errorMessage !== null;
    }
}
