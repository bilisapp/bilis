<?php

namespace App\Services\Ingest;

/**
 * Maps Bilis' simple JSON ingest payload into `otel_logs` rows.
 *
 * A payload is either a single log object or a list of them. Every field is
 * optional except the message, and records that lack one are skipped rather
 * than failing the whole request.
 */
class SimpleLogMapper
{
    /**
     * Map a decoded simple ingest payload for the given project.
     *
     * The project id is always the one the API key authenticated to, never a
     * value lifted out of the payload (SCHEMA.md R2). ProjectId is a String
     * column, so callers pass the id already cast.
     */
    public function map(mixed $payload, string $projectId): MappedLogs
    {
        if (! is_array($payload) || $payload === []) {
            return new MappedLogs(rejected: 1, errorMessage: 'Request body could not be read as a log record or a list of them.');
        }

        $records = array_is_list($payload) ? $payload : [$payload];

        $rows = [];
        $rejected = 0;
        $observedAt = LogTimestamp::now();

        foreach ($records as $record) {
            $row = is_array($record) ? $this->row($record, $projectId, $observedAt) : null;

            if ($row === null) {
                $rejected++;

                continue;
            }

            $rows[] = $row;
        }

        return new MappedLogs($rows, $rejected);
    }

    /**
     * Build a single `otel_logs` row, or null when the record has no message.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function row(array $record, string $projectId, string $observedAt): ?array
    {
        $body = $this->body($record);

        if ($body === null) {
            return null;
        }

        $level = $record['level'] ?? $record['severity'] ?? null;

        [$severityNumber, $severityText] = LogSeverity::resolve(
            null,
            is_string($level) ? $level : null,
        );

        /*
         * Every column is written explicitly, in schema order, because the INSERT
         * names its columns (SCHEMA.md R1). The simple format has no notion of
         * schema urls or scope attributes, so those are empty by construction
         * rather than by column DEFAULT.
         */
        return [
            'Timestamp' => LogTimestamp::parse($record['timestamp'] ?? $record['time'] ?? null) ?? $observedAt,
            // Normalised the way the trace mapper stores them, so the log→span link finds them.
            'TraceId' => TraceIds::lenient($record['trace_id'] ?? $record['traceId'] ?? null, TraceIds::TRACE_ID_BYTES),
            'SpanId' => TraceIds::lenient($record['span_id'] ?? $record['spanId'] ?? null, TraceIds::SPAN_ID_BYTES),
            'TraceFlags' => 0,
            'SeverityText' => $severityText,
            'SeverityNumber' => $severityNumber,
            'ServiceName' => $this->stringField($record['service'] ?? $record['service_name'] ?? null),
            'Body' => $body,
            'ResourceSchemaUrl' => '',
            'ResourceAttributes' => [],
            'ScopeSchemaUrl' => '',
            'ScopeName' => $this->stringField($record['scope'] ?? null),
            'ScopeVersion' => '',
            'ScopeAttributes' => [],
            'LogAttributes' => $this->context($record['context'] ?? $record['attributes'] ?? null),
            'EventName' => $this->stringField($record['event'] ?? null),
            'ProjectId' => $projectId,
        ];
    }

    /**
     * Read the log message, accepting either `message` or `body`.
     *
     * @param  array<string, mixed>  $record
     */
    private function body(array $record): ?string
    {
        $body = $record['message'] ?? $record['body'] ?? null;

        if (is_string($body)) {
            return $body === '' ? null : $body;
        }

        if (is_int($body) || is_float($body) || is_bool($body)) {
            return $this->stringify($body);
        }

        if (is_array($body)) {
            return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * Flatten a context object into a string map.
     *
     * @return array<string, string>
     */
    private function context(mixed $context): array
    {
        if (! is_array($context)) {
            return [];
        }

        $attributes = [];

        foreach ($context as $key => $value) {
            $attributes[(string) $key] = $this->stringify($value);
        }

        return $attributes;
    }

    /**
     * Read an optional scalar field as a string.
     */
    private function stringField(mixed $value): string
    {
        return is_string($value) ? trim($value) : ($value === null ? '' : $this->stringify($value));
    }

    /**
     * Coerce any value into the string ClickHouse expects.
     */
    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
