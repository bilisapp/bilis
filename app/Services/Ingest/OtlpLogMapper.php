<?php

namespace App\Services\Ingest;

use InvalidArgumentException;
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
        $timestamp = $this->timestamp(
            $logRecord['timeUnixNano'] ?? $logRecord['time_unix_nano'] ?? null,
            $logRecord['observedTimeUnixNano'] ?? $logRecord['observed_time_unix_nano'] ?? null,
            $observedAt,
        );

        $severityNumber = $logRecord['severityNumber'] ?? $logRecord['severity_number'] ?? null;
        $severityText = $logRecord['severityText'] ?? $logRecord['severity_text'] ?? null;

        [$severityNumber, $severityText] = LogSeverity::resolve(
            is_int($severityNumber) || (is_string($severityNumber) && ctype_digit($severityNumber))
                ? (int) $severityNumber
                : (is_string($severityNumber) ? LogSeverity::numberForText($severityNumber) : null),
            is_string($severityText) ? $severityText : null,
        );

        /*
         * The proto field is `flags`, so that is what OTLP/JSON and the
         * protobuf decoder both spell it. `traceFlags` is accepted too because
         * hand-written payloads and older docs use it.
         */
        $traceFlags = $logRecord['flags'] ?? $logRecord['traceFlags'] ?? $logRecord['trace_flags'] ?? 0;

        /*
         * Every column of the table is written explicitly, in schema order, with
         * an empty default where OTLP omits the field: the INSERT names its
         * columns, so a missing key would be a mapping bug rather than a default
         * (SCHEMA.md R1). ObservedTimestamp is not a column — it only survives
         * here as the fallback for a record that carries no event time.
         */
        return [
            'Timestamp' => $timestamp,
            'TraceId' => TraceIds::lenient($logRecord['traceId'] ?? $logRecord['trace_id'] ?? null, TraceIds::TRACE_ID_BYTES),
            'SpanId' => TraceIds::lenient($logRecord['spanId'] ?? $logRecord['span_id'] ?? null, TraceIds::SPAN_ID_BYTES),
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
     * The record's timestamp: the event time, else the observed time.
     *
     * A record that names neither is dated at ingest. A record that names a
     * time we cannot store — a digit string too long for `DateTime64`, which
     * ClickHouse would ack and then drop, or a pre-2000 value that is seconds
     * mislabelled as nanoseconds — falls through to the observed time, and is
     * rejected (counted, never a 400) when that is unusable too: re-dating a
     * line the sender did stamp would hide the sender's bug behind a wrong time.
     */
    private function timestamp(mixed $time, mixed $observedTime, string $observedAt): string
    {
        $timestamp = OtlpValues::nanos($time) ?? OtlpValues::nanos($observedTime);

        if ($timestamp !== null) {
            return $timestamp;
        }

        if ($time === null && $observedTime === null) {
            return $observedAt;
        }

        throw new InvalidArgumentException('Neither the event time nor the observed time is a storable nanosecond timestamp.');
    }

    /**
     * Flatten a list of OTLP KeyValue pairs into a string map.
     *
     * @return array<string, string>
     */
    private function attributes(mixed $attributes): array
    {
        return OtlpValues::attributes($attributes);
    }

    /**
     * Render an OTLP body, keeping plain strings verbatim.
     */
    private function body(mixed $body): string
    {
        if (is_array($body) && array_key_exists('stringValue', $body) && is_string($body['stringValue'])) {
            return $body['stringValue'];
        }

        return OtlpValues::stringify(OtlpValues::anyValue($body));
    }

    /**
     * Read a scalar field as a string.
     */
    private function string(mixed $value): string
    {
        return OtlpValues::string($value);
    }
}
