<?php

use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\Protobuf\OtlpMetricsProtobufDecoder;

/**
 * The two encodings of one metrics export, as captured by the Go generator.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function metricFixture(string $name): array
{
    $directory = __DIR__.'/../../Fixtures/otlp/';

    return [
        (string) file_get_contents($directory.$name.'.bin'),
        (array) json_decode((string) file_get_contents($directory.$name.'.json'), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/*
 * The property the decoder exists to have, as for logs and traces: protobuf in
 * and JSON in produce identical rows. The .bin of `otlp-metrics-export` is what
 * a real otlpmetrichttp exporter put on the wire.
 */
it('maps a protobuf export to the same rows as its JSON encoding', function (string $fixture, int $rejected) {
    [$protobuf, $json] = metricFixture($fixture);

    $mapper = new OtlpMetricsMapper;

    $fromProtobuf = $mapper->map((new OtlpMetricsProtobufDecoder)->decodeMetrics($protobuf), '7');
    $fromJson = $mapper->map($json, '7');

    expect($fromProtobuf->rows)->toBe($fromJson->rows)
        ->and($fromProtobuf->rows)->not->toBeEmpty()
        ->and($fromProtobuf->rejected)->toBe($rejected)
        ->and($fromJson->rejected)->toBe($rejected);
})->with([
    'sdk export' => ['otlp-metrics-export', 0],
    // The NaN gauge point and the point with no time.
    'kitchen sink' => ['otlp-metrics-kitchen-sink', 2],
]);

it('decodes what the sdk sends: every instrument, cumulative', function () {
    [$protobuf] = metricFixture('otlp-metrics-export');

    $decoded = (new OtlpMetricsProtobufDecoder)->decodeMetrics($protobuf);
    $scope = $decoded['resourceMetrics'][0]['scopeMetrics'][0];
    $metrics = collect($scope['metrics'])->keyBy('name');

    expect($scope['scope'])->toMatchArray(['name' => 'checkout.payments', 'version' => '1.4.0'])
        ->and($metrics->keys()->all())->toEqualCanonicalizing([
            'http.server.requests',
            'http.server.active_requests',
            'process.memory.usage.ratio',
            'http.server.request.duration',
            'checkout.payload.size',
        ])
        ->and($metrics['http.server.requests']['sum'])->toMatchArray(['aggregationTemporality' => 2, 'isMonotonic' => true])
        ->and($metrics['http.server.active_requests']['sum'])->not->toHaveKey('isMonotonic')
        ->and($metrics['http.server.request.duration']['histogram']['dataPoints'][0]['explicitBounds'])
        ->toBe([0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0])
        ->and($metrics['http.server.request.duration']['histogram']['dataPoints'][0]['bucketCounts'])
        ->toBe(['1', '0', '1', '2', '0', '1', '0', '1', '1'])
        ->and($metrics['checkout.payload.size']['exponentialHistogram']['dataPoints'][0]['count'])->toBe('5');
});

it('reads the shapes the sdk cannot be made to send', function () {
    [$protobuf] = metricFixture('otlp-metrics-kitchen-sink');

    $metrics = collect((new OtlpMetricsProtobufDecoder)->decodeMetrics($protobuf)['resourceMetrics'][0]['scopeMetrics'][0]['metrics'])
        ->keyBy('name');

    $gauge = $metrics['queue.depth']['gauge']['dataPoints'];
    $exponential = $metrics['payload.delta']['exponentialHistogram']['dataPoints'][0];
    $summary = $metrics['rpc.latency']['summary']['dataPoints'][0];

    expect($gauge[0]['asInt'])->toBe('-7')
        ->and($gauge[0]['exemplars'][0])->toMatchArray([
            'asInt' => '9',
            'traceId' => '5b8efff798038103d269b633813fc60c',
            'spanId' => 'eee19b7ec3c1b174',
        ])
        ->and($gauge[1])->toMatchArray(['asDouble' => 2.75, 'flags' => 1])
        ->and(is_nan($gauge[2]['asDouble']))->toBeTrue()
        ->and($metrics['queue.depth']['metadata'][0]['key'])->toBe('source')
        ->and($exponential)->toMatchArray(['scale' => -1, 'zeroCount' => '2', 'sum' => -3.5, 'zeroThreshold' => 0.001])
        ->and($exponential['positive'])->toBe(['offset' => -2, 'bucketCounts' => ['1', '0', '2']])
        ->and($exponential['negative'])->toBe(['offset' => 1, 'bucketCounts' => ['3', '1']])
        ->and($summary['quantileValues'])->toHaveCount(4)
        ->and($summary['quantileValues'][2])->toBe(['quantile' => 0.99, 'value' => 220.0]);
});

it('returns an empty request for an empty body', function () {
    expect((new OtlpMetricsProtobufDecoder)->decodeMetrics(''))->toBe(['resourceMetrics' => []]);
});

it('refuses a truncated message', function () {
    [$protobuf] = metricFixture('otlp-metrics-kitchen-sink');

    (new OtlpMetricsProtobufDecoder)->decodeMetrics(substr($protobuf, 0, (int) (strlen($protobuf) / 2)));
})->throws(MalformedProtobufException::class);
