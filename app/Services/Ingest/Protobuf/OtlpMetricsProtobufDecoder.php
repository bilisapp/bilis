<?php

namespace App\Services\Ingest\Protobuf;

/**
 * Decodes an OTLP `ExportMetricsServiceRequest` from protobuf into the array
 * shape the OTLP/JSON endpoint produces, so `OtlpMetricsMapper` never learns
 * which encoding arrived — the same contract {@see OtlpProtobufDecoder} keeps
 * for logs and traces, asserted the same way against fixtures captured from a
 * real Go exporter (`tests/Fixtures/otlp`).
 *
 * Protojson conventions this reproduces:
 *
 * - every 64-bit integer — `fixed64` times and counts, `sfixed64` `asInt`,
 *   `uint64` exponential bucket counts — is a decimal string;
 * - doubles are numbers, including the proto3 `optional` ones (`sum`, `min`,
 *   `max`), which appear only when the sender set them;
 * - enums (`aggregationTemporality`) are integers;
 * - exemplar `traceId`/`spanId` are hex, the OTLP/JSON exception to base64.
 *
 * Repeated scalars (`bucketCounts`, `explicitBounds`) are accepted packed or
 * not, as the wire format requires of a parser; a field that appears several
 * times appends.
 *
 * Auditing this file: one private method per message, as in the log/trace
 * decoder; the shared envelope (Resource, InstrumentationScope, KeyValue,
 * AnyValue, UTF-8 scrub) comes from {@see DecodesOtlpCommon}.
 *
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/main/opentelemetry/proto/metrics/v1/metrics.proto
 */
class OtlpMetricsProtobufDecoder
{
    use DecodesOtlpCommon;

    /** ExportMetricsServiceRequest: `repeated ResourceMetrics resource_metrics = 1`. */
    private const REQUEST_RESOURCE_METRICS = 1;

    /** ResourceMetrics: resource = 1, scope_metrics = 2, schema_url = 3. */
    private const RESOURCE_METRICS_RESOURCE = 1;

    private const RESOURCE_METRICS_SCOPE_METRICS = 2;

    private const RESOURCE_METRICS_SCHEMA_URL = 3;

    /** ScopeMetrics: scope = 1, metrics = 2, schema_url = 3. */
    private const SCOPE_METRICS_SCOPE = 1;

    private const SCOPE_METRICS_METRICS = 2;

    private const SCOPE_METRICS_SCHEMA_URL = 3;

    /** Metric: name/description/unit, then the `data` oneof; 4, 6 and 8 are reserved. */
    private const METRIC_NAME = 1;

    private const METRIC_DESCRIPTION = 2;

    private const METRIC_UNIT = 3;

    private const METRIC_GAUGE = 5;

    private const METRIC_SUM = 7;

    private const METRIC_HISTOGRAM = 9;

    private const METRIC_EXPONENTIAL_HISTOGRAM = 10;

    private const METRIC_SUMMARY = 11;

    private const METRIC_METADATA = 12;

    /** Gauge, Sum, Histogram, ExponentialHistogram, Summary: data_points = 1. */
    private const DATA_POINTS = 1;

    /** Sum, Histogram, ExponentialHistogram: aggregation_temporality = 2. */
    private const AGGREGATION_TEMPORALITY = 2;

    /** Sum: is_monotonic = 3. */
    private const SUM_IS_MONOTONIC = 3;

    /** Fields every data point type puts at the same numbers. */
    private const POINT_START_TIME_UNIX_NANO = 2;

    private const POINT_TIME_UNIX_NANO = 3;

    /** NumberDataPoint. */
    private const NUMBER_AS_DOUBLE = 4;

    private const NUMBER_EXEMPLARS = 5;

    private const NUMBER_AS_INT = 6;

    private const NUMBER_ATTRIBUTES = 7;

    private const NUMBER_FLAGS = 8;

    /** HistogramDataPoint. */
    private const HISTOGRAM_COUNT = 4;

    private const HISTOGRAM_SUM = 5;

    private const HISTOGRAM_BUCKET_COUNTS = 6;

    private const HISTOGRAM_EXPLICIT_BOUNDS = 7;

    private const HISTOGRAM_EXEMPLARS = 8;

    private const HISTOGRAM_ATTRIBUTES = 9;

    private const HISTOGRAM_FLAGS = 10;

    private const HISTOGRAM_MIN = 11;

    private const HISTOGRAM_MAX = 12;

    /** ExponentialHistogramDataPoint. */
    private const EXPONENTIAL_ATTRIBUTES = 1;

    private const EXPONENTIAL_COUNT = 4;

    private const EXPONENTIAL_SUM = 5;

    private const EXPONENTIAL_SCALE = 6;

    private const EXPONENTIAL_ZERO_COUNT = 7;

    private const EXPONENTIAL_POSITIVE = 8;

    private const EXPONENTIAL_NEGATIVE = 9;

    private const EXPONENTIAL_FLAGS = 10;

    private const EXPONENTIAL_EXEMPLARS = 11;

    private const EXPONENTIAL_MIN = 12;

    private const EXPONENTIAL_MAX = 13;

    private const EXPONENTIAL_ZERO_THRESHOLD = 14;

    /** ExponentialHistogramDataPoint.Buckets: offset = 1, bucket_counts = 2. */
    private const BUCKETS_OFFSET = 1;

    private const BUCKETS_BUCKET_COUNTS = 2;

    /** SummaryDataPoint. */
    private const SUMMARY_COUNT = 4;

    private const SUMMARY_SUM = 5;

    private const SUMMARY_QUANTILE_VALUES = 6;

    private const SUMMARY_ATTRIBUTES = 7;

    private const SUMMARY_FLAGS = 8;

    /** SummaryDataPoint.ValueAtQuantile: quantile = 1, value = 2. */
    private const QUANTILE_QUANTILE = 1;

    private const QUANTILE_VALUE = 2;

    /** Exemplar. */
    private const EXEMPLAR_TIME_UNIX_NANO = 2;

    private const EXEMPLAR_AS_DOUBLE = 3;

    private const EXEMPLAR_SPAN_ID = 4;

    private const EXEMPLAR_TRACE_ID = 5;

    private const EXEMPLAR_AS_INT = 6;

    private const EXEMPLAR_FILTERED_ATTRIBUTES = 7;

    /**
     * Decode an `ExportMetricsServiceRequest` body.
     *
     * @return array{resourceMetrics: list<array<string, mixed>>}
     *
     * @throws MalformedProtobufException
     */
    public function decodeMetrics(string $body): array
    {
        $reader = new ProtobufReader($body);
        $resourceMetrics = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::REQUEST_RESOURCE_METRICS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $resourceMetrics[] = $this->resourceMetrics($reader->readMessage());

                continue;
            }

            $reader->skip($wireType);
        }

        return ['resourceMetrics' => $resourceMetrics];
    }

    /**
     * ResourceMetrics.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function resourceMetrics(ProtobufReader $reader): array
    {
        $resourceMetrics = [];
        $scopeMetrics = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::RESOURCE_METRICS_RESOURCE => $resourceMetrics['resource'] = $this->resource($reader->readMessage()),
                self::RESOURCE_METRICS_SCOPE_METRICS => $scopeMetrics[] = $this->scopeMetrics($reader->readMessage()),
                self::RESOURCE_METRICS_SCHEMA_URL => $resourceMetrics['schemaUrl'] = $this->utf8($reader->readLengthDelimited()),
                default => $reader->skip($wireType),
            };
        }

        $resourceMetrics['scopeMetrics'] = $scopeMetrics;

        return $resourceMetrics;
    }

    /**
     * ScopeMetrics.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function scopeMetrics(ProtobufReader $reader): array
    {
        $scopeMetrics = [];
        $metrics = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::SCOPE_METRICS_SCOPE => $scopeMetrics['scope'] = $this->scope($reader->readMessage()),
                self::SCOPE_METRICS_METRICS => $metrics[] = $this->metric($reader->readMessage()),
                self::SCOPE_METRICS_SCHEMA_URL => $scopeMetrics['schemaUrl'] = $this->utf8($reader->readLengthDelimited()),
                default => $reader->skip($wireType),
            };
        }

        $scopeMetrics['metrics'] = $metrics;

        return $scopeMetrics;
    }

    /**
     * Metric, with whichever of the five `data` kinds is set.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function metric(ProtobufReader $reader): array
    {
        $metric = [];
        $metadata = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::METRIC_NAME => $metric['name'] = $this->utf8($reader->readLengthDelimited()),
                self::METRIC_DESCRIPTION => $metric['description'] = $this->utf8($reader->readLengthDelimited()),
                self::METRIC_UNIT => $metric['unit'] = $this->utf8($reader->readLengthDelimited()),
                self::METRIC_GAUGE => $metric['gauge'] = $this->dataPoints($reader->readMessage(), $this->numberDataPoint(...)),
                self::METRIC_SUM => $metric['sum'] = $this->dataPoints($reader->readMessage(), $this->numberDataPoint(...)),
                self::METRIC_HISTOGRAM => $metric['histogram'] = $this->dataPoints($reader->readMessage(), $this->histogramDataPoint(...)),
                self::METRIC_EXPONENTIAL_HISTOGRAM => $metric['exponentialHistogram'] = $this->dataPoints($reader->readMessage(), $this->exponentialHistogramDataPoint(...)),
                self::METRIC_SUMMARY => $metric['summary'] = $this->dataPoints($reader->readMessage(), $this->summaryDataPoint(...)),
                self::METRIC_METADATA => $metadata[] = $this->keyValue($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        if ($metadata !== []) {
            $metric['metadata'] = $metadata;
        }

        return $metric;
    }

    /**
     * Gauge, Sum, Histogram, ExponentialHistogram or Summary.
     *
     * The five share a layout — `data_points = 1`, and for the three that have
     * one, `aggregation_temporality = 2` — so one method reads them all, handed
     * the reader for its point type. `isMonotonic` only exists on Sum.
     *
     * @param  callable(ProtobufReader): array<string, mixed>  $point
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function dataPoints(ProtobufReader $reader, callable $point): array
    {
        $data = [];
        $points = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::DATA_POINTS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $points[] = $point($reader->readMessage());
            } elseif ($field === self::AGGREGATION_TEMPORALITY && $wireType === ProtobufReader::WIRE_VARINT) {
                $data['aggregationTemporality'] = $reader->readVarint();
            } elseif ($field === self::SUM_IS_MONOTONIC && $wireType === ProtobufReader::WIRE_VARINT) {
                $data['isMonotonic'] = $reader->readVarint() !== 0;
            } else {
                $reader->skip($wireType);
            }
        }

        $data['dataPoints'] = $points;

        return $data;
    }

    /**
     * NumberDataPoint, the point of a Gauge or a Sum.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function numberDataPoint(ProtobufReader $reader): array
    {
        $point = [];
        $attributes = [];
        $exemplars = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::POINT_START_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['startTimeUnixNano'] = $reader->readFixed64(),
                $field === self::POINT_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['timeUnixNano'] = $reader->readFixed64(),
                $field === self::NUMBER_AS_DOUBLE && $wireType === ProtobufReader::WIRE_FIXED64 => $point['asDouble'] = $reader->readDouble(),
                $field === self::NUMBER_AS_INT && $wireType === ProtobufReader::WIRE_FIXED64 => $point['asInt'] = $reader->readSfixed64(),
                $field === self::NUMBER_FLAGS && $wireType === ProtobufReader::WIRE_VARINT => $point['flags'] = $reader->readVarint(),
                $field === self::NUMBER_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $attributes[] = $this->keyValue($reader->readMessage()),
                $field === self::NUMBER_EXEMPLARS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $exemplars[] = $this->exemplar($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        return $this->withLists($point, $attributes, $exemplars);
    }

    /**
     * HistogramDataPoint.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function histogramDataPoint(ProtobufReader $reader): array
    {
        $point = [];
        $attributes = [];
        $exemplars = [];
        $bucketCounts = [];
        $explicitBounds = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::POINT_START_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['startTimeUnixNano'] = $reader->readFixed64(),
                $field === self::POINT_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['timeUnixNano'] = $reader->readFixed64(),
                $field === self::HISTOGRAM_COUNT && $wireType === ProtobufReader::WIRE_FIXED64 => $point['count'] = $reader->readFixed64(),
                $field === self::HISTOGRAM_SUM && $wireType === ProtobufReader::WIRE_FIXED64 => $point['sum'] = $reader->readDouble(),
                $field === self::HISTOGRAM_MIN && $wireType === ProtobufReader::WIRE_FIXED64 => $point['min'] = $reader->readDouble(),
                $field === self::HISTOGRAM_MAX && $wireType === ProtobufReader::WIRE_FIXED64 => $point['max'] = $reader->readDouble(),
                $field === self::HISTOGRAM_FLAGS && $wireType === ProtobufReader::WIRE_VARINT => $point['flags'] = $reader->readVarint(),
                $field === self::HISTOGRAM_BUCKET_COUNTS => array_push($bucketCounts, ...$reader->readRepeatedFixed64($wireType)),
                $field === self::HISTOGRAM_EXPLICIT_BOUNDS => array_push($explicitBounds, ...$reader->readRepeatedDouble($wireType)),
                $field === self::HISTOGRAM_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $attributes[] = $this->keyValue($reader->readMessage()),
                $field === self::HISTOGRAM_EXEMPLARS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $exemplars[] = $this->exemplar($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        if ($bucketCounts !== []) {
            $point['bucketCounts'] = $bucketCounts;
        }

        if ($explicitBounds !== []) {
            $point['explicitBounds'] = $explicitBounds;
        }

        return $this->withLists($point, $attributes, $exemplars);
    }

    /**
     * ExponentialHistogramDataPoint.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function exponentialHistogramDataPoint(ProtobufReader $reader): array
    {
        $point = [];
        $attributes = [];
        $exemplars = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::POINT_START_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['startTimeUnixNano'] = $reader->readFixed64(),
                $field === self::POINT_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['timeUnixNano'] = $reader->readFixed64(),
                $field === self::EXPONENTIAL_COUNT && $wireType === ProtobufReader::WIRE_FIXED64 => $point['count'] = $reader->readFixed64(),
                $field === self::EXPONENTIAL_SUM && $wireType === ProtobufReader::WIRE_FIXED64 => $point['sum'] = $reader->readDouble(),
                $field === self::EXPONENTIAL_SCALE && $wireType === ProtobufReader::WIRE_VARINT => $point['scale'] = $reader->readSint32(),
                $field === self::EXPONENTIAL_ZERO_COUNT && $wireType === ProtobufReader::WIRE_FIXED64 => $point['zeroCount'] = $reader->readFixed64(),
                $field === self::EXPONENTIAL_ZERO_THRESHOLD && $wireType === ProtobufReader::WIRE_FIXED64 => $point['zeroThreshold'] = $reader->readDouble(),
                $field === self::EXPONENTIAL_MIN && $wireType === ProtobufReader::WIRE_FIXED64 => $point['min'] = $reader->readDouble(),
                $field === self::EXPONENTIAL_MAX && $wireType === ProtobufReader::WIRE_FIXED64 => $point['max'] = $reader->readDouble(),
                $field === self::EXPONENTIAL_FLAGS && $wireType === ProtobufReader::WIRE_VARINT => $point['flags'] = $reader->readVarint(),
                $field === self::EXPONENTIAL_POSITIVE && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $point['positive'] = $this->buckets($reader->readMessage()),
                $field === self::EXPONENTIAL_NEGATIVE && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $point['negative'] = $this->buckets($reader->readMessage()),
                $field === self::EXPONENTIAL_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $attributes[] = $this->keyValue($reader->readMessage()),
                $field === self::EXPONENTIAL_EXEMPLARS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $exemplars[] = $this->exemplar($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        return $this->withLists($point, $attributes, $exemplars);
    }

    /**
     * ExponentialHistogramDataPoint.Buckets.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function buckets(ProtobufReader $reader): array
    {
        $buckets = [];
        $counts = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::BUCKETS_OFFSET && $wireType === ProtobufReader::WIRE_VARINT => $buckets['offset'] = $reader->readSint32(),
                $field === self::BUCKETS_BUCKET_COUNTS => array_push($counts, ...$reader->readRepeatedUint64($wireType)),
                default => $reader->skip($wireType),
            };
        }

        if ($counts !== []) {
            $buckets['bucketCounts'] = $counts;
        }

        return $buckets;
    }

    /**
     * SummaryDataPoint.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function summaryDataPoint(ProtobufReader $reader): array
    {
        $point = [];
        $attributes = [];
        $quantiles = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::POINT_START_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['startTimeUnixNano'] = $reader->readFixed64(),
                $field === self::POINT_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $point['timeUnixNano'] = $reader->readFixed64(),
                $field === self::SUMMARY_COUNT && $wireType === ProtobufReader::WIRE_FIXED64 => $point['count'] = $reader->readFixed64(),
                $field === self::SUMMARY_SUM && $wireType === ProtobufReader::WIRE_FIXED64 => $point['sum'] = $reader->readDouble(),
                $field === self::SUMMARY_FLAGS && $wireType === ProtobufReader::WIRE_VARINT => $point['flags'] = $reader->readVarint(),
                $field === self::SUMMARY_QUANTILE_VALUES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $quantiles[] = $this->valueAtQuantile($reader->readMessage()),
                $field === self::SUMMARY_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $attributes[] = $this->keyValue($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        if ($quantiles !== []) {
            $point['quantileValues'] = $quantiles;
        }

        return $this->withLists($point, $attributes, []);
    }

    /**
     * SummaryDataPoint.ValueAtQuantile.
     *
     * @return array<string, float>
     *
     * @throws MalformedProtobufException
     */
    private function valueAtQuantile(ProtobufReader $reader): array
    {
        $pair = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::QUANTILE_QUANTILE && $wireType === ProtobufReader::WIRE_FIXED64 => $pair['quantile'] = $reader->readDouble(),
                $field === self::QUANTILE_VALUE && $wireType === ProtobufReader::WIRE_FIXED64 => $pair['value'] = $reader->readDouble(),
                default => $reader->skip($wireType),
            };
        }

        return $pair;
    }

    /**
     * Exemplar: one raw measurement, usually naming the span it was taken in.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function exemplar(ProtobufReader $reader): array
    {
        $exemplar = [];
        $attributes = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            match (true) {
                $field === self::EXEMPLAR_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64 => $exemplar['timeUnixNano'] = $reader->readFixed64(),
                $field === self::EXEMPLAR_AS_DOUBLE && $wireType === ProtobufReader::WIRE_FIXED64 => $exemplar['asDouble'] = $reader->readDouble(),
                $field === self::EXEMPLAR_AS_INT && $wireType === ProtobufReader::WIRE_FIXED64 => $exemplar['asInt'] = $reader->readSfixed64(),
                $field === self::EXEMPLAR_SPAN_ID && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $exemplar['spanId'] = bin2hex($reader->readLengthDelimited()),
                $field === self::EXEMPLAR_TRACE_ID && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $exemplar['traceId'] = bin2hex($reader->readLengthDelimited()),
                $field === self::EXEMPLAR_FILTERED_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED => $attributes[] = $this->keyValue($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        if ($attributes !== []) {
            $exemplar['filteredAttributes'] = $attributes;
        }

        return $exemplar;
    }

    /**
     * Attach a point's repeated fields the way protojson does: only when present.
     *
     * @param  array<string, mixed>  $point
     * @param  list<array<string, mixed>>  $attributes
     * @param  list<array<string, mixed>>  $exemplars
     * @return array<string, mixed>
     */
    private function withLists(array $point, array $attributes, array $exemplars): array
    {
        if ($attributes !== []) {
            $point['attributes'] = $attributes;
        }

        if ($exemplars !== []) {
            $point['exemplars'] = $exemplars;
        }

        return $point;
    }
}
