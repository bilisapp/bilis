<?php

namespace App\Services\Ingest;

/**
 * The result of mapping an ingest payload into `otel_traces` rows.
 *
 * The twin of {@see MappedLogs}. It is a separate type rather than a shared one
 * because OTLP reports rejections per signal — `rejectedSpans` here against
 * `rejectedLogRecords` there — and a controller that could mix the two up would
 * report the wrong field to an exporter that reads it.
 */
class MappedSpans
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly int $rejected = 0,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * The number of spans that will be handed to ClickHouse.
     */
    public function accepted(): int
    {
        return count($this->rows);
    }

    /**
     * Whether any span of the payload had to be dropped.
     */
    public function hasRejections(): bool
    {
        return $this->rejected > 0 || $this->errorMessage !== null;
    }
}
