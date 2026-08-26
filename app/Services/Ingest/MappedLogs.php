<?php

namespace App\Services\Ingest;

/**
 * The result of mapping an ingest payload into `otel_logs` rows.
 */
class MappedLogs
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
     * The number of records that will be handed to ClickHouse.
     */
    public function accepted(): int
    {
        return count($this->rows);
    }

    /**
     * Whether any record of the payload had to be dropped.
     */
    public function hasRejections(): bool
    {
        return $this->rejected > 0 || $this->errorMessage !== null;
    }
}
