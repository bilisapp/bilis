<?php

namespace App\Services\Ingest;

use InvalidArgumentException;
use Throwable;

/**
 * Maps an OTLP `ExportTraceServiceRequest` into `otel_traces` rows, one per span.
 *
 * The twin of {@see OtlpLogMapper}, and forgiving in the same way: a span that
 * cannot be understood is counted as rejected and the rest of the payload is
 * still accepted. Both encodings reach this class in the same array shape, so it
 * never learns whether JSON or protobuf arrived.
 */
class OtlpTraceMapper
{
    /**
     * The resource attribute carrying the service name.
     */
    private const SERVICE_NAME_ATTRIBUTE = 'service.name';

    /**
     * Map a decoded OTLP export request for the given project.
     *
     * The project id is always the one the API key authenticated to, never a
     * value lifted out of the payload (SCHEMA.md R2).
     */
    public function map(mixed $payload, string $projectId): MappedSpans
    {
        if (! is_array($payload)) {
            return new MappedSpans(errorMessage: 'Request body could not be read as an OTLP ExportTraceServiceRequest.');
        }

        $resourceSpans = $payload['resourceSpans'] ?? $payload['resource_spans'] ?? [];

        if (! is_array($resourceSpans)) {
            return new MappedSpans(errorMessage: 'The resourceSpans field must be an array.');
        }

        $rows = [];
        $rejected = 0;

        foreach ($resourceSpans as $resourceSpan) {
            if (! is_array($resourceSpan)) {
                $rejected++;

                continue;
            }

            $resource = $resourceSpan['resource'] ?? [];
            $resourceAttributes = OtlpValues::attributes(is_array($resource) ? ($resource['attributes'] ?? []) : []);
            $serviceName = $resourceAttributes[self::SERVICE_NAME_ATTRIBUTE] ?? '';

            $scopeSpans = $resourceSpan['scopeSpans'] ?? $resourceSpan['scope_spans'] ?? [];

            if (! is_array($scopeSpans)) {
                $rejected++;

                continue;
            }

            foreach ($scopeSpans as $scopeSpan) {
                if (! is_array($scopeSpan)) {
                    $rejected++;

                    continue;
                }

                $scope = $scopeSpan['scope'] ?? [];
                $scopeName = is_array($scope) ? OtlpValues::string($scope['name'] ?? '') : '';
                $scopeVersion = is_array($scope) ? OtlpValues::string($scope['version'] ?? '') : '';

                $spans = $scopeSpan['spans'] ?? [];

                if (! is_array($spans)) {
                    $rejected++;

                    continue;
                }

                foreach ($spans as $span) {
                    if (! is_array($span)) {
                        $rejected++;

                        continue;
                    }

                    try {
                        $rows[] = $this->row(
                            $span,
                            $projectId,
                            $resourceAttributes,
                            $serviceName,
                            $scopeName,
                            $scopeVersion,
                        );
                    } catch (Throwable) {
                        $rejected++;
                    }
                }
            }
        }

        return new MappedSpans($rows, $rejected);
    }

    /**
     * Build a single `otel_traces` row from an OTLP span.
     *
     * @param  array<string, mixed>  $span
     * @param  array<string, string>  $resourceAttributes
     * @return array<string, mixed>
     */
    private function row(
        array $span,
        string $projectId,
        array $resourceAttributes,
        string $serviceName,
        string $scopeName,
        string $scopeVersion,
    ): array {
        $start = $span['startTimeUnixNano'] ?? $span['start_time_unix_nano'] ?? null;
        $end = $span['endTimeUnixNano'] ?? $span['end_time_unix_nano'] ?? null;

        $timestamp = $this->timestamp($start);

        /*
         * A span without a readable trace id or span id is rejected, not stored:
         * `trace_summary_mv` keeps `WHERE TraceId != ''`, so such a row would
         * never appear in the list, never be reachable from a log line, and
         * still have been reported to the exporter as accepted.
         */
        $traceId = TraceIds::hex($span['traceId'] ?? $span['trace_id'] ?? null, TraceIds::TRACE_ID_BYTES);
        $spanId = TraceIds::hex($span['spanId'] ?? $span['span_id'] ?? null, TraceIds::SPAN_ID_BYTES);

        if ($traceId === '' || $spanId === '') {
            throw new InvalidArgumentException('A span needs a non-zero trace id and span id.');
        }

        $status = $span['status'] ?? [];
        $status = is_array($status) ? $status : [];

        [$eventTimestamps, $eventNames, $eventAttributes] = $this->events($span['events'] ?? [], $timestamp);
        [$linkTraceIds, $linkSpanIds, $linkTraceStates, $linkAttributes] = $this->links($span['links'] ?? []);

        /*
         * Every column is written explicitly, in schema order, with an empty
         * default where OTLP omits the field: the INSERT names its columns, so a
         * missing key would be a mapping bug rather than a default (R1).
         */
        return [
            'Timestamp' => $timestamp,
            'TraceId' => $traceId,
            'SpanId' => $spanId,
            'ParentSpanId' => TraceIds::hex($span['parentSpanId'] ?? $span['parent_span_id'] ?? null, TraceIds::SPAN_ID_BYTES),
            'TraceState' => OtlpValues::string($span['traceState'] ?? $span['trace_state'] ?? ''),
            'SpanName' => OtlpValues::string($span['name'] ?? ''),
            'SpanKind' => SpanSemantics::kind($span['kind'] ?? null),
            'ServiceName' => $serviceName,
            'ResourceAttributes' => $resourceAttributes,
            'ScopeName' => $scopeName,
            'ScopeVersion' => $scopeVersion,
            'SpanAttributes' => OtlpValues::attributes($span['attributes'] ?? []),
            'Duration' => $this->duration($start, $end),
            'StatusCode' => SpanSemantics::statusCode($status['code'] ?? null),
            'StatusMessage' => OtlpValues::string($status['message'] ?? ''),
            'Events.Timestamp' => $eventTimestamps,
            'Events.Name' => $eventNames,
            'Events.Attributes' => $eventAttributes,
            'Links.TraceId' => $linkTraceIds,
            'Links.SpanId' => $linkSpanIds,
            'Links.TraceState' => $linkTraceStates,
            'Links.Attributes' => $linkAttributes,
            'ProjectId' => $projectId,
        ];
    }

    /**
     * The span's start, formatted for the `Timestamp` column.
     *
     * A span that carries no start at all is dated at ingest, as it always was.
     * A span that carries one we cannot store is rejected instead: a digit
     * string too long for `DateTime64` would have been acked and then dropped
     * by ClickHouse, and a value before 2000 is seconds or milliseconds sent as
     * nanoseconds — a row dated 1970 expires at the next TTL merge, so writing
     * it with a made-up time would only hide the sender's bug.
     */
    private function timestamp(mixed $start): string
    {
        if ($start === null) {
            return LogTimestamp::now();
        }

        return OtlpValues::nanos($start)
            ?? throw new InvalidArgumentException('The span start time is not a storable nanosecond timestamp.');
    }

    /**
     * The span's duration in nanoseconds.
     *
     * Clamped at zero: `Duration` is a `UInt64`, and a clock that ran backwards
     * between the two timestamps would otherwise wrap a negative into something
     * near 1.8e19 and make the span the slowest one in every percentile.
     */
    private function duration(mixed $start, mixed $end): int
    {
        $startNanos = OtlpValues::nanosAsInt($start);
        $endNanos = OtlpValues::nanosAsInt($end);

        if ($startNanos === null || $endNanos === null) {
            return 0;
        }

        return max(0, $endNanos - $startNanos);
    }

    /**
     * Flatten a span's events into the three position-aligned arrays (R12).
     *
     * Built in one pass so the three can never fall out of step: an event that
     * contributes a name has to contribute a timestamp and an attribute map at
     * the same index, or every later event's name would attach to the wrong
     * event's attributes.
     *
     * An event whose timestamp is missing or unusable takes the span's own
     * start: it is the nearest true moment, where epoch zero would be a lie
     * that the 30-day TTL then erases.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<array<string, string>>}
     */
    private function events(mixed $events, string $spanStart): array
    {
        $timestamps = [];
        $names = [];
        $attributes = [];

        if (! is_array($events)) {
            return [$timestamps, $names, $attributes];
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $timestamps[] = OtlpValues::nanos($event['timeUnixNano'] ?? $event['time_unix_nano'] ?? null)
                ?? $spanStart;
            $names[] = OtlpValues::string($event['name'] ?? '');
            $attributes[] = OtlpValues::attributes($event['attributes'] ?? []);
        }

        return [$timestamps, $names, $attributes];
    }

    /**
     * Flatten a span's links into the four position-aligned arrays (R12).
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<array<string, string>>}
     */
    private function links(mixed $links): array
    {
        $traceIds = [];
        $spanIds = [];
        $traceStates = [];
        $attributes = [];

        if (! is_array($links)) {
            return [$traceIds, $spanIds, $traceStates, $attributes];
        }

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $traceIds[] = TraceIds::hex($link['traceId'] ?? $link['trace_id'] ?? null, TraceIds::TRACE_ID_BYTES);
            $spanIds[] = TraceIds::hex($link['spanId'] ?? $link['span_id'] ?? null, TraceIds::SPAN_ID_BYTES);
            $traceStates[] = OtlpValues::string($link['traceState'] ?? $link['trace_state'] ?? '');
            $attributes[] = OtlpValues::attributes($link['attributes'] ?? []);
        }

        return [$traceIds, $spanIds, $traceStates, $attributes];
    }
}
