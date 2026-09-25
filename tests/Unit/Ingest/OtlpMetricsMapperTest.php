<?php

use App\Services\Ingest\MetricTimestamp;
use App\Services\Ingest\OtlpMetricsMapper;

/**
 * An export carrying one metric of the given kind.
 *
 * @param  array<string, mixed>  $data  The body of the `data` kind.
 * @return array<string, mixed>
 */
function metricExport(string $kind, array $data, array $metric = []): array
{
    return [
        'resourceMetrics' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
                ['key' => 'host.name', 'value' => ['stringValue' => 'web-1']],
            ]],
            'schemaUrl' => 'https://opentelemetry.io/schemas/1.26.0',
            'scopeMetrics' => [[
                'scope' => ['name' => 'checkout.payments', 'version' => '1.4.0', 'droppedAttributesCount' => 2],
                'metrics' => [[
                    'name' => 'http.server.requests',
                    'description' => 'Requests served.',
                    'unit' => '{request}',
                    $kind => $data,
                    ...$metric,
                ]],
            ]],
        ]],
    ];
}

/**
 * A point at 2026-01-01 00:00:00.5 UTC, the half second truncated away.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function metricPoint(array $overrides = []): array
{
    return [
        'attributes' => [['key' => 'http.route', 'value' => ['stringValue' => '/checkout']]],
        'startTimeUnixNano' => '1767225540000000000',
        'timeUnixNano' => '1767225600500000000',
        ...$overrides,
    ];
}

/**
 * Map an export and return its rows for one table.
 *
 * @param  array<string, mixed>  $payload
 * @return list<array<string, mixed>>
 */
function metricRows(array $payload, string $table): array
{
    return (new OtlpMetricsMapper)->map($payload, '42')->rows[$table] ?? [];
}

it('maps a gauge point to a full row, every column in schema order', function () {
    $rows = metricRows(metricExport('gauge', ['dataPoints' => [metricPoint([
        'asDouble' => 0.625,
        'flags' => 1,
        'exemplars' => [[
            'filteredAttributes' => [['key' => 'job', 'value' => ['stringValue' => 'SendInvoice']]],
            'timeUnixNano' => '1767225600000000000',
            'asInt' => '9',
            'traceId' => '5B8EFFF798038103D269B633813FC60C',
            'spanId' => 'eee19b7ec3c1b174',
        ]],
    ])]]), OtlpMetricsMapper::TABLE_GAUGE);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe([
            'ResourceAttributes' => ['service.name' => 'checkout', 'host.name' => 'web-1'],
            'ResourceSchemaUrl' => 'https://opentelemetry.io/schemas/1.26.0',
            'ScopeName' => 'checkout.payments',
            'ScopeVersion' => '1.4.0',
            'ScopeAttributes' => [],
            'ScopeDroppedAttrCount' => 2,
            'ScopeSchemaUrl' => '',
            'ServiceName' => 'checkout',
            'MetricName' => 'http.server.requests',
            'MetricDescription' => 'Requests served.',
            'MetricUnit' => '{request}',
            'Attributes' => ['http.route' => '/checkout'],
            'StartTimeUnix' => '2025-12-31 23:59:00',
            'TimeUnix' => '2026-01-01 00:00:00',
            'Value' => 0.625,
            'Flags' => 1,
            'Exemplars.FilteredAttributes' => [['job' => 'SendInvoice']],
            'Exemplars.TimeUnix' => ['2026-01-01 00:00:00'],
            'Exemplars.Value' => [9.0],
            'Exemplars.SpanId' => ['eee19b7ec3c1b174'],
            'Exemplars.TraceId' => ['5b8efff798038103d269b633813fc60c'],
            'ProjectId' => '42',
        ]);
});

it('maps a sum with its temporality and monotonicity', function (mixed $temporality, int $stored) {
    [$row] = metricRows(metricExport('sum', [
        'aggregationTemporality' => $temporality,
        'isMonotonic' => true,
        'dataPoints' => [metricPoint(['asInt' => '-12'])],
    ]), OtlpMetricsMapper::TABLE_SUM);

    expect($row['Value'])->toBe(-12.0)
        ->and($row['AggregationTemporality'])->toBe($stored)
        ->and($row['IsMonotonic'])->toBeTrue()
        ->and(array_slice(array_keys($row), -3))->toBe(['AggregationTemporality', 'IsMonotonic', 'ProjectId']);
})->with([
    'number' => [2, 2],
    'enum name' => ['AGGREGATION_TEMPORALITY_DELTA', 1],
    'nonsense' => [7, 0],
]);

it('maps an explicit-bucket histogram', function () {
    [$row] = metricRows(metricExport('histogram', [
        'aggregationTemporality' => 1,
        'dataPoints' => [metricPoint([
            'count' => '6',
            'sum' => 12.5,
            'min' => 0.25,
            'max' => 7,
            'bucketCounts' => ['1', '2', '0', '3'],
            'explicitBounds' => [0.5, 1, 5],
        ])],
    ]), OtlpMetricsMapper::TABLE_HISTOGRAM);

    expect($row)->toMatchArray([
        'Count' => 6,
        'Sum' => 12.5,
        'BucketCounts' => [1, 2, 0, 3],
        'ExplicitBounds' => [0.5, 1.0, 5.0],
        'Min' => 0.25,
        'Max' => 7.0,
        'AggregationTemporality' => 1,
    ]);
});

it('writes a histogram point with no recorded value as zeroes, as the exporter does', function () {
    [$row] = metricRows(metricExport('histogram', ['dataPoints' => [metricPoint(['flags' => 1])]]), OtlpMetricsMapper::TABLE_HISTOGRAM);

    expect($row)->toMatchArray(['Count' => 0, 'Sum' => 0.0, 'Min' => 0.0, 'Max' => 0.0, 'BucketCounts' => [], 'ExplicitBounds' => [], 'Flags' => 1]);
});

it('maps an exponential histogram with both sides and a negative scale', function () {
    [$row] = metricRows(metricExport('exponentialHistogram', [
        'aggregationTemporality' => 2,
        'dataPoints' => [metricPoint([
            'count' => '9',
            'sum' => -3.5,
            'scale' => -1,
            'zeroCount' => '2',
            'positive' => ['offset' => -2, 'bucketCounts' => ['1', '0', '2']],
            'negative' => ['offset' => 1, 'bucketCounts' => ['3', '1']],
        ])],
    ]), OtlpMetricsMapper::TABLE_EXPONENTIAL_HISTOGRAM);

    expect($row)->toMatchArray([
        'Count' => 9,
        'Sum' => -3.5,
        'Scale' => -1,
        'ZeroCount' => 2,
        'PositiveOffset' => -2,
        'PositiveBucketCounts' => [1, 0, 2],
        'NegativeOffset' => 1,
        'NegativeBucketCounts' => [3, 1],
        'AggregationTemporality' => 2,
    ]);
});

it('maps a summary into position-aligned quantile columns', function () {
    [$row] = metricRows(metricExport('summary', ['dataPoints' => [metricPoint([
        'count' => '1000',
        'sum' => 48250.5,
        'quantileValues' => [['quantile' => 0.5, 'value' => 41.5], ['quantile' => 0.99, 'value' => 220]],
    ])]]), OtlpMetricsMapper::TABLE_SUMMARY);

    expect($row)->toMatchArray([
        'Count' => 1000,
        'Sum' => 48250.5,
        'ValueAtQuantiles.Quantile' => [0.5, 0.99],
        'ValueAtQuantiles.Value' => [41.5, 220.0],
    ])->and($row)->not->toHaveKey('Exemplars.Value');
});

it('takes the project id from the caller only', function () {
    $payload = metricExport('gauge', ['dataPoints' => [metricPoint(['asDouble' => 1, 'attributes' => [
        ['key' => 'ProjectId', 'value' => ['stringValue' => '999']],
    ]])]]);

    [$row] = (new OtlpMetricsMapper)->map($payload, '42')->rows[OtlpMetricsMapper::TABLE_GAUGE];

    expect($row['ProjectId'])->toBe('42');
});

it('accepts snake_case field names', function () {
    $mapped = (new OtlpMetricsMapper)->map([
        'resource_metrics' => [[
            'scope_metrics' => [[
                'metrics' => [[
                    'name' => 'queue.depth',
                    'exponential_histogram' => [
                        'aggregation_temporality' => 1,
                        'data_points' => [[
                            'time_unix_nano' => '1767225600000000000',
                            'zero_count' => '1',
                            'positive' => ['bucket_counts' => ['4']],
                        ]],
                    ],
                ]],
            ]],
        ]],
    ], '42');

    expect($mapped->rejected)->toBe(0)
        ->and($mapped->rows[OtlpMetricsMapper::TABLE_EXPONENTIAL_HISTOGRAM][0])->toMatchArray([
            'ZeroCount' => 1,
            'PositiveBucketCounts' => [4],
            'AggregationTemporality' => 1,
        ]);
});

it('rejects a point it cannot store and keeps the rest', function (mixed $point) {
    $mapped = (new OtlpMetricsMapper)->map(metricExport('gauge', ['dataPoints' => [
        metricPoint(['asDouble' => 1]),
        $point,
    ]]), '42');

    expect($mapped->rejected)->toBe(1)
        ->and($mapped->rows[OtlpMetricsMapper::TABLE_GAUGE])->toHaveCount(1);
})->with([
    'no time' => [['asDouble' => 1]],
    'time zero' => [['asDouble' => 1, 'timeUnixNano' => '0']],
    'seconds sent as nanoseconds' => [['asDouble' => 1, 'timeUnixNano' => '1767225600']],
    'past the DateTime range' => [['asDouble' => 1, 'timeUnixNano' => '4291747200000000000']],
    'NaN from protojson' => [metricPoint(['asDouble' => 'NaN'])],
    'infinity from protojson' => [metricPoint(['asDouble' => '-Infinity'])],
    'NaN from protobuf' => [metricPoint(['asDouble' => NAN])],
    'a value that is not a number' => [metricPoint(['asDouble' => 'twelve'])],
    'an asInt that is not an integer' => [metricPoint(['asInt' => '1.5'])],
    'flags past 32 bits' => [metricPoint(['asDouble' => 1, 'flags' => 4294967296])],
    'not an object' => ['nope'],
]);

it('rejects a histogram whose buckets do not match its bounds', function (array $overrides) {
    $mapped = (new OtlpMetricsMapper)->map(metricExport('histogram', ['dataPoints' => [metricPoint($overrides)]]), '42');

    expect($mapped->rejected)->toBe(1)
        ->and($mapped->rows)->toBe([]);
})->with([
    'one bucket short' => [['count' => '3', 'bucketCounts' => ['1', '2'], 'explicitBounds' => [0.5, 1]]],
    'a negative count' => [['count' => '-3']],
    'a NaN bound' => [['bucketCounts' => ['1', '2'], 'explicitBounds' => ['NaN']]],
    'a NaN sum' => [['sum' => 'NaN']],
]);

it('drops an exemplar it cannot store without costing the point', function () {
    [$row] = metricRows(metricExport('gauge', ['dataPoints' => [metricPoint([
        'asDouble' => 1,
        'exemplars' => [
            ['asDouble' => 'NaN'],
            ['asDouble' => 2, 'traceId' => 'not-an-id', 'timeUnixNano' => '12'],
        ],
    ])]]), OtlpMetricsMapper::TABLE_GAUGE);

    expect($row['Exemplars.Value'])->toBe([2.0])
        ->and($row['Exemplars.TraceId'])->toBe([''])
        ->and($row['Exemplars.TimeUnix'])->toBe([MetricTimestamp::EPOCH]);
});

it('counts a metric with no data kind it knows as one rejection', function () {
    $mapped = (new OtlpMetricsMapper)->map(metricExport('histogramish', ['dataPoints' => [metricPoint()]]), '42');

    expect($mapped->rejected)->toBe(1)
        ->and($mapped->accepted())->toBe(0);
});

it('reports an unreadable payload without throwing', function (mixed $payload, string $message) {
    $mapped = (new OtlpMetricsMapper)->map($payload, '42');

    expect($mapped->errorMessage)->toBe($message)
        ->and($mapped->hasRejections())->toBeTrue();
})->with([
    'not an object' => [null, 'Request body could not be read as an OTLP ExportMetricsServiceRequest.'],
    'resourceMetrics not a list' => [['resourceMetrics' => 'x'], 'The resourceMetrics field must be an array.'],
]);

it('keeps the start time as the epoch when the sender set none, as the exporter does', function () {
    [$row] = metricRows(metricExport('gauge', ['dataPoints' => [metricPoint(['asDouble' => 1, 'startTimeUnixNano' => null])]]), OtlpMetricsMapper::TABLE_GAUGE);

    expect($row['StartTimeUnix'])->toBe('1970-01-01 00:00:00');
});
