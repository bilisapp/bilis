<?php

namespace App\Services\Ingest;

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
     * The hex width of a trace id and of a span id.
     */
    private const TRACE_ID_LENGTH = 32;

    private const SPAN_ID_LENGTH = 16;

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

        $timestamp = OtlpValues::nanos($start) ?? LogTimestamp::now();

        $status = $span['status'] ?? [];
        $status = is_array($status) ? $status : [];

        [$eventTimestamps, $eventNames, $eventAttributes] = $this->events($span['events'] ?? []);
        [$linkTraceIds, $linkSpanIds, $linkTraceStates, $linkAttributes] = $this->links($span['links'] ?? []);

        /*
         * Every column is written explicitly, in schema order, with an empty
         * default where OTLP omits the field: the INSERT names its columns, so a
         * missing key would be a mapping bug rather than a default (R1).
         */
        return [
            'Timestamp' => $timestamp,
            'TraceId' => $this->hexId($span['traceId'] ?? $span['trace_id'] ?? null, self::TRACE_ID_LENGTH),
            'SpanId' => $this->hexId($span['spanId'] ?? $span['span_id'] ?? null, self::SPAN_ID_LENGTH),
            'ParentSpanId' => $this->hexId($span['parentSpanId'] ?? $span['parent_span_id'] ?? null, self::SPAN_ID_LENGTH),
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
     * Normalise a trace or span id to the lowercase hex the table stores.
     *
     * An all-zero id is the proto's way of spelling "absent", and so is a
     * missing field or one of the wrong width. All of them become `''`, which
     * matters most for `ParentSpanId`: `trace_summary_mv` decides which span is
     * the root with `ParentSpanId = ''`, so a root arriving as sixteen zeroes
     * would leave the trace with no root name at all.
     */
    private function hexId(mixed $value, int $length): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));

        if (strlen($value) !== $length || preg_match('/^[0-9a-f]+$/', $value) !== 1) {
            return '';
        }

        return trim($value, '0') === '' ? '' : $value;
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
     * @return array{0: list<string>, 1: list<string>, 2: list<array<string, string>>}
     */
    private function events(mixed $events): array
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
                ?? LogTimestamp::fromNanos(0);
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

            $traceIds[] = $this->hexId($link['traceId'] ?? $link['trace_id'] ?? null, self::TRACE_ID_LENGTH);
            $spanIds[] = $this->hexId($link['spanId'] ?? $link['span_id'] ?? null, self::SPAN_ID_LENGTH);
            $traceStates[] = OtlpValues::string($link['traceState'] ?? $link['trace_state'] ?? '');
            $attributes[] = OtlpValues::attributes($link['attributes'] ?? []);
        }

        return [$traceIds, $spanIds, $traceStates, $attributes];
    }
}
