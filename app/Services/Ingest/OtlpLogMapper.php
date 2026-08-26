<?php

namespace App\Services\Ingest;

use Throwable;

/**
 * Maps an OTLP/HTTP JSON `ExportLogsServiceRequest` into `otel_logs` rows.
 *
 * Mapping is best effort: records that cannot be understood are counted as
 * rejected and the remainder of the payload is still accepted.
 */
class OtlpLogMapper
{
    /**
     * The resource attribute carrying the service name.
     */
    private const SERVICE_NAME_ATTRIBUTE = 'service.name';

    /**
     * Map a decoded OTLP export request for the given project.
     *
     * The project id is always the one the API key authenticated to, never a
     * value lifted out of the payload (SCHEMA.md R2). ProjectId is a String
     * column, so callers pass the id already cast.
     */
    public function map(mixed $payload, string $projectId): MappedLogs
    {
        if (! is_array($payload)) {
            return new MappedLogs(errorMessage: 'Request body could not be read as an OTLP ExportLogsServiceRequest.');
        }

        $resourceLogs = $payload['resourceLogs'] ?? $payload['resource_logs'] ?? [];

        if (! is_array($resourceLogs)) {
            return new MappedLogs(errorMessage: 'The resourceLogs field must be an array.');
        }

        $rows = [];
        $rejected = 0;
        $observedAt = LogTimestamp::now();

        foreach ($resourceLogs as $resourceLog) {
            if (! is_array($resourceLog)) {
                $rejected++;

                continue;
            }

            $resource = $resourceLog['resource'] ?? [];
            $resourceAttributes = $this->attributes(is_array($resource) ? ($resource['attributes'] ?? []) : []);
            $serviceName = $resourceAttributes[self::SERVICE_NAME_ATTRIBUTE] ?? '';
            $resourceSchemaUrl = $this->string($resourceLog['schemaUrl'] ?? $resourceLog['schema_url'] ?? '');

            $scopeLogs = $resourceLog['scopeLogs'] ?? $resourceLog['scope_logs'] ?? [];

            if (! is_array($scopeLogs)) {
                $rejected++;

                continue;
            }

            foreach ($scopeLogs as $scopeLog) {
                if (! is_array($scopeLog)) {
                    $rejected++;

                    continue;
                }

                $scope = $scopeLog['scope'] ?? [];
                $scopeFields = [
                    'schemaUrl' => $this->string($scopeLog['schemaUrl'] ?? $scopeLog['schema_url'] ?? ''),
                    'name' => is_array($scope) ? $this->string($scope['name'] ?? '') : '',
                    'version' => is_array($scope) ? $this->string($scope['version'] ?? '') : '',
                    'attributes' => $this->attributes(is_array($scope) ? ($scope['attributes'] ?? []) : []),
                ];

                $logRecords = $scopeLog['logRecords'] ?? $scopeLog['log_records'] ?? [];

                if (! is_array($logRecords)) {
                    $rejected++;

                    continue;
                }

                foreach ($logRecords as $logRecord) {
                    if (! is_array($logRecord)) {
                        $rejected++;

                        continue;
                    }

                    try {
                        $rows[] = $this->row(
                            $logRecord,
                            $projectId,
                            $resourceAttributes,
                            $resourceSchemaUrl,
                            $serviceName,
                            $scopeFields,
                            $observedAt,
                        );
                    } catch (Throwable) {
                        $rejected++;
                    }
                }
            }
        }

        return new MappedLogs($rows, $rejected);
    }

    /**
     * Build a single `otel_logs` row from an OTLP log record.
     *
     * @param  array<string, mixed>  $logRecord
     * @param  array<string, string>  $resourceAttributes
     * @param  array{schemaUrl: string, name: string, version: string, attributes: array<string, string>}  $scope
     * @return array<string, mixed>
     */
    private function row(
        array $logRecord,
        string $projectId,
        array $resourceAttributes,
        string $resourceSchemaUrl,
        string $serviceName,
        array $scope,
        string $observedAt,
    ): array {
        $observedTimestamp = $this->nanos($logRecord['observedTimeUnixNano'] ?? $logRecord['observed_time_unix_nano'] ?? null)
            ?? $observedAt;

        $timestamp = $this->nanos($logRecord['timeUnixNano'] ?? $logRecord['time_unix_nano'] ?? null)
            ?? $observedTimestamp;

        $severityNumber = $logRecord['severityNumber'] ?? $logRecord['severity_number'] ?? null;
        $severityText = $logRecord['severityText'] ?? $logRecord['severity_text'] ?? null;

        [$severityNumber, $severityText] = LogSeverity::resolve(
            is_int($severityNumber) || (is_string($severityNumber) && ctype_digit($severityNumber))
                ? (int) $severityNumber
                : (is_string($severityNumber) ? LogSeverity::numberForText($severityNumber) : null),
            is_string($severityText) ? $severityText : null,
        );

        $traceFlags = $logRecord['traceFlags'] ?? $logRecord['trace_flags'] ?? 0;

        /*
         * Every column of the table is written explicitly, in schema order, with
         * an empty default where OTLP omits the field: the INSERT names its
         * columns, so a missing key would be a mapping bug rather than a default
         * (SCHEMA.md R1). ObservedTimestamp is not a column — it only survives
         * here as the fallback for a record that carries no event time.
         */
        return [
            'Timestamp' => $timestamp,
            'TraceId' => $this->string($logRecord['traceId'] ?? $logRecord['trace_id'] ?? ''),
            'SpanId' => $this->string($logRecord['spanId'] ?? $logRecord['span_id'] ?? ''),
            'TraceFlags' => is_numeric($traceFlags) ? max(0, min(255, (int) $traceFlags)) : 0,
            'SeverityText' => $severityText,
            'SeverityNumber' => $severityNumber,
            'ServiceName' => $serviceName,
            'Body' => $this->body($logRecord['body'] ?? null),
            'ResourceSchemaUrl' => $resourceSchemaUrl,
            'ResourceAttributes' => $resourceAttributes,
            'ScopeSchemaUrl' => $scope['schemaUrl'],
            'ScopeName' => $scope['name'],
            'ScopeVersion' => $scope['version'],
            'ScopeAttributes' => $scope['attributes'],
            'LogAttributes' => $this->attributes($logRecord['attributes'] ?? []),
            'EventName' => $this->string($logRecord['eventName'] ?? $logRecord['event_name'] ?? ''),
            'ProjectId' => $projectId,
        ];
    }

    /**
     * Format a nanosecond timestamp field, if it holds a usable value.
     */
    private function nanos(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? LogTimestamp::fromNanos($value) : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value) && ltrim($value, '0') !== '') {
            return LogTimestamp::fromNanos($value);
        }

        return null;
    }

    /**
     * Flatten a list of OTLP KeyValue pairs into a string map.
     *
     * @return array<string, string>
     */
    private function attributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $flattened = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $key = $attribute['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $flattened[$key] = $this->stringify($this->anyValue($attribute['value'] ?? null));
        }

        return $flattened;
    }

    /**
     * Render an OTLP body, keeping plain strings verbatim.
     */
    private function body(mixed $body): string
    {
        if (is_array($body) && array_key_exists('stringValue', $body) && is_string($body['stringValue'])) {
            return $body['stringValue'];
        }

        return $this->stringify($this->anyValue($body));
    }

    /**
     * Unwrap an OTLP AnyValue into a plain PHP value.
     */
    private function anyValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }

        if (array_key_exists('boolValue', $value)) {
            return (bool) $value['boolValue'];
        }

        if (array_key_exists('intValue', $value)) {
            return is_string($value['intValue']) || is_int($value['intValue']) ? $value['intValue'] : null;
        }

        if (array_key_exists('doubleValue', $value)) {
            return is_numeric($value['doubleValue']) ? (float) $value['doubleValue'] : null;
        }

        if (array_key_exists('bytesValue', $value)) {
            return is_string($value['bytesValue']) ? $value['bytesValue'] : null;
        }

        if (array_key_exists('arrayValue', $value)) {
            $values = is_array($value['arrayValue']) ? ($value['arrayValue']['values'] ?? []) : [];

            return array_map(fn (mixed $item): mixed => $this->anyValue($item), is_array($values) ? $values : []);
        }

        if (array_key_exists('kvlistValue', $value)) {
            $values = is_array($value['kvlistValue']) ? ($value['kvlistValue']['values'] ?? []) : [];

            return $this->attributes($values);
        }

        return $value;
    }

    /**
     * Coerce an unwrapped value into the string ClickHouse expects.
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

    /**
     * Read a scalar field as a string.
     */
    private function string(mixed $value): string
    {
        return is_string($value) ? $value : ($value === null ? '' : $this->stringify($value));
    }
}
