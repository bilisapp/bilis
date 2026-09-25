<?php

namespace App\Services\Ingest;

use InvalidArgumentException;
use Throwable;

/**
 * Maps an OTLP `ExportMetricsServiceRequest` into rows for the five
 * `otel_metrics_*` tables, one row per data point.
 *
 * The twin of {@see OtlpTraceMapper}, and forgiving in the same way: a data
 * point that cannot be stored is counted as rejected and the rest of the
 * payload is still accepted (R8). Both encodings reach this class in the same
 * array shape, so it never learns whether JSON or protobuf arrived.
 *
 * What rejects a point, and why each would otherwise be acked and lost:
 *
 * - a time that is absent or outside {@see MetricTimestamp}'s window;
 * - a value that is not a finite number — NaN and ±Infinity are legal OTLP
 *   (protojson spells them as strings) but `json_encode` refuses them, which
 *   would fail the insert of every point in the batch;
 * - a histogram whose bucket counts do not number one more than its bounds;
 * - a count that does not fit an integer.
 *
 * Rows carry every column in schema order (R1) and a `ProjectId` taken only
 * from the authenticated key (R2).
 *
 * @phpstan-type Row array<string, mixed>
 */
class OtlpMetricsMapper
{
    public const TABLE_GAUGE = 'otel_metrics_gauge';

    public const TABLE_SUM = 'otel_metrics_sum';

    public const TABLE_HISTOGRAM = 'otel_metrics_histogram';

    public const TABLE_EXPONENTIAL_HISTOGRAM = 'otel_metrics_exponential_histogram';

    public const TABLE_SUMMARY = 'otel_metrics_summary';

    /**
     * Each OTLP `data` kind, both spellings, and the table it is written to.
     */
    private const KINDS = [
        'gauge' => self::TABLE_GAUGE,
        'sum' => self::TABLE_SUM,
        'histogram' => self::TABLE_HISTOGRAM,
        'exponentialHistogram' => self::TABLE_EXPONENTIAL_HISTOGRAM,
        'exponential_histogram' => self::TABLE_EXPONENTIAL_HISTOGRAM,
        'summary' => self::TABLE_SUMMARY,
    ];

    /**
     * `AggregationTemporality` by enum name, for a JSON sender that spells it.
     */
    private const TEMPORALITIES = [
        'AGGREGATION_TEMPORALITY_UNSPECIFIED' => 0,
        'AGGREGATION_TEMPORALITY_DELTA' => 1,
        'AGGREGATION_TEMPORALITY_CUMULATIVE' => 2,
    ];

    /**
     * The resource attribute carrying the service name.
     */
    private const SERVICE_NAME_ATTRIBUTE = 'service.name';

    /**
     * Map a decoded OTLP export request for the given project.
     */
    public function map(mixed $payload, string $projectId): MappedMetrics
    {
        if (! is_array($payload)) {
            return new MappedMetrics(errorMessage: 'Request body could not be read as an OTLP ExportMetricsServiceRequest.');
        }

        $resourceMetrics = $payload['resourceMetrics'] ?? $payload['resource_metrics'] ?? [];

        if (! is_array($resourceMetrics)) {
            return new MappedMetrics(errorMessage: 'The resourceMetrics field must be an array.');
        }

        $rows = [];
        $rejected = 0;

        foreach ($resourceMetrics as $resourceMetric) {
            if (! is_array($resourceMetric)) {
                $rejected++;

                continue;
            }

            $resource = $resourceMetric['resource'] ?? [];
            $resourceAttributes = OtlpValues::attributes(is_array($resource) ? ($resource['attributes'] ?? []) : []);

            $envelope = [
                'ResourceAttributes' => $resourceAttributes,
                'ResourceSchemaUrl' => OtlpValues::string($resourceMetric['schemaUrl'] ?? $resourceMetric['schema_url'] ?? ''),
                'ServiceName' => $resourceAttributes[self::SERVICE_NAME_ATTRIBUTE] ?? '',
            ];

            $scopeMetrics = $resourceMetric['scopeMetrics'] ?? $resourceMetric['scope_metrics'] ?? [];

            if (! is_array($scopeMetrics)) {
                $rejected++;

                continue;
            }

            foreach ($scopeMetrics as $scopeMetric) {
                if (! is_array($scopeMetric)) {
                    $rejected++;

                    continue;
                }

                $scope = $scopeMetric['scope'] ?? [];
                $scope = is_array($scope) ? $scope : [];

                $scopeEnvelope = $envelope + [
                    'ScopeName' => OtlpValues::string($scope['name'] ?? ''),
                    'ScopeVersion' => OtlpValues::string($scope['version'] ?? ''),
                    'ScopeAttributes' => OtlpValues::attributes($scope['attributes'] ?? []),
                    'ScopeDroppedAttrCount' => $this->droppedCount($scope['droppedAttributesCount'] ?? $scope['dropped_attributes_count'] ?? 0),
                    'ScopeSchemaUrl' => OtlpValues::string($scopeMetric['schemaUrl'] ?? $scopeMetric['schema_url'] ?? ''),
                ];

                $metrics = $scopeMetric['metrics'] ?? [];

                if (! is_array($metrics)) {
                    $rejected++;

                    continue;
                }

                foreach ($metrics as $metric) {
                    $rejected += $this->mapMetric($metric, $scopeEnvelope, $projectId, $rows);
                }
            }
        }

        return new MappedMetrics($rows, $rejected);
    }

    /**
     * Map one Metric's data points into `$rows`, returning how many were rejected.
     *
     * @param  array<string, mixed>  $envelope
     * @param  array<string, list<Row>>  $rows
     */
    private function mapMetric(mixed $metric, array $envelope, string $projectId, array &$rows): int
    {
        if (! is_array($metric)) {
            return 1;
        }

        [$kind, $data] = $this->data($metric);

        if ($kind === null || ! is_array($data)) {
            // A metric with no data kind we know, or a malformed one, carries
            // no point we could count; it is one rejected item.
            return 1;
        }

        $points = $data['dataPoints'] ?? $data['data_points'] ?? [];

        if (! is_array($points)) {
            return 1;
        }

        $table = self::KINDS[$kind];

        $identity = [
            'MetricName' => OtlpValues::string($metric['name'] ?? ''),
            'MetricDescription' => OtlpValues::string($metric['description'] ?? ''),
            'MetricUnit' => OtlpValues::string($metric['unit'] ?? ''),
        ];

        $rejected = 0;

        foreach ($points as $point) {
            try {
                if (! is_array($point)) {
                    throw new InvalidArgumentException('A data point must be an object.');
                }

                $rows[$table][] = $this->row($table, $point, $data, $envelope, $identity, $projectId);
            } catch (Throwable) {
                $rejected++;
            }
        }

        return $rejected;
    }

    /**
     * The one `data` kind a Metric carries, and its body.
     *
     * @param  array<string, mixed>  $metric
     * @return array{0: string|null, 1: mixed}
     */
    private function data(array $metric): array
    {
        foreach (array_keys(self::KINDS) as $kind) {
            if (array_key_exists($kind, $metric)) {
                return [$kind, $metric[$kind]];
            }
        }

        return [null, null];
    }

    /**
     * Build one row, every column in schema order.
     *
     * @param  array<string, mixed>  $point
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $envelope
     * @param  array<string, string>  $identity
     * @return Row
     */
    private function row(string $table, array $point, array $data, array $envelope, array $identity, string $projectId): array
    {
        $common = [
            'ResourceAttributes' => $envelope['ResourceAttributes'],
            'ResourceSchemaUrl' => $envelope['ResourceSchemaUrl'],
            'ScopeName' => $envelope['ScopeName'],
            'ScopeVersion' => $envelope['ScopeVersion'],
            'ScopeAttributes' => $envelope['ScopeAttributes'],
            'ScopeDroppedAttrCount' => $envelope['ScopeDroppedAttrCount'],
            'ScopeSchemaUrl' => $envelope['ScopeSchemaUrl'],
            'ServiceName' => $envelope['ServiceName'],
            ...$identity,
            'Attributes' => OtlpValues::attributes($point['attributes'] ?? []),
            'StartTimeUnix' => MetricTimestamp::optional($point['startTimeUnixNano'] ?? $point['start_time_unix_nano'] ?? null),
            'TimeUnix' => MetricTimestamp::fromNanos($point['timeUnixNano'] ?? $point['time_unix_nano'] ?? null)
                ?? throw new InvalidArgumentException('A data point needs a storable time.'),
        ];

        $flags = $this->unsigned($point['flags'] ?? 0);

        if ($flags > 4_294_967_295) {
            // `Flags` is UInt32: ClickHouse would refuse it after the ack.
            throw new InvalidArgumentException('flags must fit 32 bits.');
        }

        $specific = match ($table) {
            self::TABLE_GAUGE => [
                'Value' => $this->numberValue($point),
                'Flags' => $flags,
                ...$this->exemplars($point),
            ],
            self::TABLE_SUM => [
                'Value' => $this->numberValue($point),
                'Flags' => $flags,
                ...$this->exemplars($point),
                'AggregationTemporality' => $this->temporality($data),
                'IsMonotonic' => $this->boolean($data['isMonotonic'] ?? $data['is_monotonic'] ?? false),
            ],
            self::TABLE_HISTOGRAM => [
                'Count' => $this->unsigned($point['count'] ?? 0),
                'Sum' => $this->optionalFinite($point['sum'] ?? null),
                ...$this->explicitBuckets($point),
                ...$this->exemplars($point),
                'Flags' => $flags,
                'Min' => $this->optionalFinite($point['min'] ?? null),
                'Max' => $this->optionalFinite($point['max'] ?? null),
                'AggregationTemporality' => $this->temporality($data),
            ],
            self::TABLE_EXPONENTIAL_HISTOGRAM => [
                'Count' => $this->unsigned($point['count'] ?? 0),
                'Sum' => $this->optionalFinite($point['sum'] ?? null),
                'Scale' => $this->signed32($point['scale'] ?? 0),
                'ZeroCount' => $this->unsigned($point['zeroCount'] ?? $point['zero_count'] ?? 0),
                ...$this->exponentialBuckets($point['positive'] ?? null, 'Positive'),
                ...$this->exponentialBuckets($point['negative'] ?? null, 'Negative'),
                ...$this->exemplars($point),
                'Flags' => $flags,
                'Min' => $this->optionalFinite($point['min'] ?? null),
                'Max' => $this->optionalFinite($point['max'] ?? null),
                'AggregationTemporality' => $this->temporality($data),
            ],
            self::TABLE_SUMMARY => [
                'Count' => $this->unsigned($point['count'] ?? 0),
                'Sum' => $this->optionalFinite($point['sum'] ?? null),
                ...$this->quantiles($point['quantileValues'] ?? $point['quantile_values'] ?? []),
                'Flags' => $flags,
            ],
            default => throw new InvalidArgumentException("No metrics table is called {$table}."),
        };

        return [...$common, ...$specific, 'ProjectId' => $projectId];
    }

    /**
     * A NumberDataPoint's value, from whichever of `asDouble` / `asInt` is set.
     *
     * Neither set is the proto's "empty" value, which the exporter writes as
     * zero; a point flagged "no recorded value" is the usual case.
     *
     * @param  array<string, mixed>  $point
     */
    private function numberValue(array $point): float
    {
        $int = $point['asInt'] ?? $point['as_int'] ?? null;

        if ($int !== null) {
            if (is_int($int)) {
                return (float) $int;
            }

            if (is_string($int) && preg_match('/^-?\d+$/', $int) === 1) {
                return (float) $int;
            }

            throw new InvalidArgumentException('asInt must be an integer.');
        }

        return $this->finite($point['asDouble'] ?? $point['as_double'] ?? 0);
    }

    /**
     * A finite float, or a rejection.
     *
     * Protojson writes the non-finite doubles as the strings `NaN`, `Infinity`
     * and `-Infinity`; the protobuf path hands them over as PHP floats. Either
     * way they cannot be stored, so either way they reject.
     */
    private function finite(mixed $value): float
    {
        $number = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => throw new InvalidArgumentException('A value must be a number.'),
        };

        if (! is_finite($number)) {
            throw new InvalidArgumentException('A value must be finite.');
        }

        return $number;
    }

    /**
     * A proto3 `optional double` (sum, min, max): zero when absent, as the
     * exporter writes it.
     */
    private function optionalFinite(mixed $value): float
    {
        return $value === null ? 0.0 : $this->finite($value);
    }

    /**
     * A 64-bit unsigned count, from protojson's string or a JSON number.
     */
    private function unsigned(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && $value === (string) (int) $value) {
            return (int) $value;
        }

        if (is_float($value) && $value >= 0 && $value < PHP_INT_MAX && floor($value) === $value) {
            return (int) $value;
        }

        throw new InvalidArgumentException('A count must be a non-negative integer.');
    }

    /**
     * The scope's dropped attribute count: bookkeeping, so a value we cannot
     * read is zero rather than a reason to refuse every point under the scope.
     */
    private function droppedCount(mixed $value): int
    {
        try {
            return min($this->unsigned($value), 4_294_967_295);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * An `Int32` column (scale, bucket offsets).
     */
    private function signed32(mixed $value): int
    {
        $int = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw new InvalidArgumentException('Expected a 32-bit integer.'),
        };

        if ($int < -2_147_483_648 || $int > 2_147_483_647) {
            throw new InvalidArgumentException('Expected a 32-bit integer.');
        }

        return $int;
    }

    /**
     * A JSON boolean, tolerating the quoted form some hand-rolled senders use.
     */
    private function boolean(mixed $value): bool
    {
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : (bool) $value;
    }

    /**
     * `AggregationTemporality` as the exporter stores it: 0, 1 (delta) or 2 (cumulative).
     *
     * @param  array<string, mixed>  $data
     */
    private function temporality(array $data): int
    {
        $value = $data['aggregationTemporality'] ?? $data['aggregation_temporality'] ?? 0;

        if (is_string($value) && isset(self::TEMPORALITIES[$value])) {
            return self::TEMPORALITIES[$value];
        }

        $int = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);

        return in_array($int, [0, 1, 2], true) ? $int : 0;
    }

    /**
     * `BucketCounts` / `ExplicitBounds` for an explicit-bucket histogram.
     *
     * A histogram with N bounds has N + 1 buckets; anything else cannot be
     * read as a distribution, so it is rejected rather than stored skewed. Both
     * empty is legal: a point with no recorded value.
     *
     * @param  array<string, mixed>  $point
     * @return array{BucketCounts: list<int>, ExplicitBounds: list<float>}
     */
    private function explicitBuckets(array $point): array
    {
        $counts = $point['bucketCounts'] ?? $point['bucket_counts'] ?? [];
        $bounds = $point['explicitBounds'] ?? $point['explicit_bounds'] ?? [];

        if (! is_array($counts) || ! is_array($bounds)) {
            throw new InvalidArgumentException('Bucket counts and bounds must be arrays.');
        }

        $counts = array_values(array_map($this->unsigned(...), $counts));
        $bounds = array_values(array_map($this->finite(...), $bounds));

        if ($counts !== [] && count($counts) !== count($bounds) + 1) {
            throw new InvalidArgumentException('A histogram needs one more bucket than it has bounds.');
        }

        return ['BucketCounts' => $counts, 'ExplicitBounds' => $bounds];
    }

    /**
     * `{Side}Offset` / `{Side}BucketCounts` for one side of an exponential histogram.
     *
     * @return array<string, int|list<int>>
     */
    private function exponentialBuckets(mixed $buckets, string $side): array
    {
        $buckets = is_array($buckets) ? $buckets : [];
        $counts = $buckets['bucketCounts'] ?? $buckets['bucket_counts'] ?? [];

        if (! is_array($counts)) {
            throw new InvalidArgumentException('Bucket counts must be an array.');
        }

        return [
            $side.'Offset' => $this->signed32($buckets['offset'] ?? 0),
            $side.'BucketCounts' => array_values(array_map($this->unsigned(...), $counts)),
        ];
    }

    /**
     * The `ValueAtQuantiles` Nested columns of a summary point.
     *
     * @return array{'ValueAtQuantiles.Quantile': list<float>, 'ValueAtQuantiles.Value': list<float>}
     */
    private function quantiles(mixed $quantiles): array
    {
        if (! is_array($quantiles)) {
            throw new InvalidArgumentException('quantileValues must be an array.');
        }

        $quantile = [];
        $value = [];

        foreach ($quantiles as $pair) {
            if (! is_array($pair)) {
                throw new InvalidArgumentException('A quantile must be an object.');
            }

            // Position-aligned (as Events.* and Links.* are, R12): both or neither.
            $quantile[] = $this->finite($pair['quantile'] ?? 0);
            $value[] = $this->finite($pair['value'] ?? 0);
        }

        return ['ValueAtQuantiles.Quantile' => $quantile, 'ValueAtQuantiles.Value' => $value];
    }

    /**
     * The `Exemplars` Nested columns of a point.
     *
     * An exemplar is garnish on a measurement, so one that cannot be stored —
     * a non-finite value — is dropped on its own and never costs the point.
     * Ids use the strict reader: an exemplar's whole purpose is the link to a
     * span, and an id we cannot normalise could never make it.
     *
     * @param  array<string, mixed>  $point
     * @return array<string, list<mixed>>
     */
    private function exemplars(array $point): array
    {
        $exemplars = $point['exemplars'] ?? [];
        $columns = [
            'Exemplars.FilteredAttributes' => [],
            'Exemplars.TimeUnix' => [],
            'Exemplars.Value' => [],
            'Exemplars.SpanId' => [],
            'Exemplars.TraceId' => [],
        ];

        if (! is_array($exemplars)) {
            return $columns;
        }

        foreach ($exemplars as $exemplar) {
            if (! is_array($exemplar)) {
                continue;
            }

            try {
                $value = $this->numberValue($exemplar);
            } catch (InvalidArgumentException) {
                continue;
            }

            $columns['Exemplars.FilteredAttributes'][] = OtlpValues::attributes($exemplar['filteredAttributes'] ?? $exemplar['filtered_attributes'] ?? []);
            $columns['Exemplars.TimeUnix'][] = MetricTimestamp::optional($exemplar['timeUnixNano'] ?? $exemplar['time_unix_nano'] ?? null);
            $columns['Exemplars.Value'][] = $value;
            $columns['Exemplars.SpanId'][] = TraceIds::hex($exemplar['spanId'] ?? $exemplar['span_id'] ?? null, TraceIds::SPAN_ID_BYTES);
            $columns['Exemplars.TraceId'][] = TraceIds::hex($exemplar['traceId'] ?? $exemplar['trace_id'] ?? null, TraceIds::TRACE_ID_BYTES);
        }

        return $columns;
    }
}
