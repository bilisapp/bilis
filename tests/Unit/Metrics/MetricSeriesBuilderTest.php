<?php

use App\Services\Metrics\MetricSeriesBuilder;

/**
 * A chart context: three one-minute buckets from 1000, read from one bucket earlier.
 *
 * @return array<string, mixed>
 */
function seriesContext(bool $grouped = false, string $type = 'sum'): array
{
    return [
        'metric' => 'http.server.requests',
        'type' => $type,
        'unit' => '{request}',
        'grouped' => $grouped,
        'interval' => 60,
        'starts' => [1020, 1080, 1140],
        'readFrom' => 960,
        'droppedSeries' => 0,
    ];
}

/**
 * The points of the one line with the given label.
 *
 * @param  array<string, mixed>  $result
 * @return list<float|null>
 */
function linePoints(array $result, string $label, ?string $stat = null): array
{
    foreach ($result['series'] as $series) {
        if ($series['label'] === $label && ($stat === null || $series['stat'] === $stat)) {
            return $series['points'];
        }
    }

    throw new RuntimeException("No line labelled {$label}.");
}

it('turns a cumulative counter into a rate from per-series deltas', function () {
    $result = (new MetricSeriesBuilder)->cumulativeRates([
        ['S' => 'a', 'Grp' => '', 'Bucket' => 960, 'V' => 100, 'Start' => 500],
        ['S' => 'a', 'Grp' => '', 'Bucket' => 1020, 'V' => 160, 'Start' => 500],
        ['S' => 'a', 'Grp' => '', 'Bucket' => 1080, 'V' => 280, 'Start' => 500],
        ['S' => 'b', 'Grp' => '', 'Bucket' => 960, 'V' => 10, 'Start' => 500],
        ['S' => 'b', 'Grp' => '', 'Bucket' => 1020, 'V' => 70, 'Start' => 500],
    ], seriesContext());

    // Bucket 1020: (160-100 + 70-10) / 60 = 2/s. 1080: (280-160) / 60. 1140: nothing reported.
    expect($result['kind'])->toBe('rate')
        ->and(linePoints($result, 'http.server.requests'))->toBe([2.0, 2.0, null])
        ->and($result['buckets'])->toBe(['1970-01-01 00:17:00', '1970-01-01 00:18:00', '1970-01-01 00:19:00']);
});

it('never draws a negative spike when a counter resets', function (array $reset, array $expected) {
    $result = (new MetricSeriesBuilder)->cumulativeRates([
        ['S' => 'a', 'Grp' => '', 'Bucket' => 960, 'V' => 600, 'Start' => 500],
        ['S' => 'a', 'Grp' => '', 'Bucket' => 1020, 'V' => 660, 'Start' => 500],
        ['S' => 'a', 'Grp' => '', 'Bucket' => 1080, ...$reset],
    ], seriesContext());

    // The new value is the whole increase since the restart, over the time
    // that actually passed: since the last point, or since the new start.
    expect(linePoints($result, 'http.server.requests'))->toBe($expected);
})->with([
    'the value dropped' => [['V' => 30, 'Start' => 500], [1.0, 0.5, null]],
    'a new start time, 30 s before the point' => [['V' => 30, 'Start' => 1050], [1.0, 1.0, null]],
]);

it('divides each increase by the time between points, not by the bucket width', function () {
    // Exported every two minutes into one-minute buckets: 240 over 120 s is
    // 2/s, where dividing by the bucket would claim 4/s.
    $result = (new MetricSeriesBuilder)->cumulativeRates([
        ['S' => 'a', 'Grp' => '', 'Bucket' => 960, 'At' => 1000, 'V' => 100, 'Start' => 500],
        ['S' => 'a', 'Grp' => '', 'Bucket' => 1080, 'At' => 1120, 'V' => 340, 'Start' => 500],
    ], seriesContext());

    expect(linePoints($result, 'http.server.requests'))->toBe([null, 2.0, null]);
});

it('counts a series that began inside the read from zero, and one that began before only as a baseline', function () {
    $result = (new MetricSeriesBuilder)->cumulativeRates([
        ['S' => 'old', 'Grp' => '', 'Bucket' => 1020, 'V' => 900, 'Start' => 100],
        ['S' => 'new', 'Grp' => '', 'Bucket' => 1020, 'V' => 60, 'Start' => 1000],
    ], seriesContext());

    // The new series counted 60 in the 20 s since it started: 3/s.
    expect(linePoints($result, 'http.server.requests'))->toBe([3.0, null, null]);
});

it('keeps the ten heaviest groups and counts the rest', function () {
    $rows = [];

    foreach (range(1, 12) as $i) {
        $rows[] = ['Bucket' => 1020, 'Grp' => "route-{$i}", 'V' => $i * 60, 'W' => $i];
    }

    $result = (new MetricSeriesBuilder)->levels($rows, seriesContext(grouped: true), 'rate', perSecond: true);

    expect($result['series'])->toHaveCount(10)
        ->and($result['truncatedGroups'])->toBe(2)
        ->and($result['series'][0]['label'])->toBe('route-12')
        ->and($result['series'][0]['group'])->toBe('route-12')
        ->and($result['series'][0]['points'])->toBe([12.0, null, null])
        ->and(array_column($result['series'], 'label'))->not->toContain('route-1');
});

it('labels a series without the grouping attribute', function () {
    $result = (new MetricSeriesBuilder)->levels([
        ['Bucket' => 1020, 'Grp' => '', 'V' => 5, 'W' => 1],
    ], seriesContext(grouped: true, type: 'gauge'), 'max', perSecond: false);

    expect($result['kind'])->toBe('value')
        ->and($result['series'][0])->toMatchArray(['label' => '(none)', 'group' => '', 'stat' => 'max']);
});

it('reads percentiles off a cumulative explicit histogram after differencing', function () {
    $point = fn (int $bucket, int $k): array => [
        'S' => 'a', 'Grp' => '', 'Bucket' => $bucket, 'Start' => 500,
        'Bounds' => [0.1, 0.5, 1.0], 'Counts' => [10 * $k, 10 * $k, 0, 0], 'C' => 20 * $k,
    ];

    $result = (new MetricSeriesBuilder)->explicitDistributions([$point(960, 1), $point(1020, 2), $point(1080, 3)], seriesContext(type: 'histogram'), cumulative: true);

    expect($result['kind'])->toBe('distribution')
        ->and(array_column($result['series'], 'stat'))->toBe(['p50', 'p95', 'p99'])
        ->and(linePoints($result, 'p50'))->toBe([0.1, 0.1, null])
        ->and(linePoints($result, 'p95'))->toBe([0.46, 0.46, null]);
});

it('merges only the dominant bound set and reports the others', function () {
    $result = (new MetricSeriesBuilder)->explicitDistributions([
        ['Grp' => '', 'Bucket' => 1020, 'Bounds' => [1.0], 'Counts' => [9, 1], 'C' => 10],
        ['Grp' => '', 'Bucket' => 1020, 'Bounds' => [2.0, 4.0], 'Counts' => [1, 0, 0], 'C' => 1],
    ], seriesContext(type: 'histogram'), cumulative: false);

    expect($result['droppedSeries'])->toBe(1)
        ->and(linePoints($result, 'p50')[0])->toEqualWithDelta(1 / 1.8, 1e-6);
});

it('reads percentiles off a delta exponential histogram merged across scales', function () {
    $result = (new MetricSeriesBuilder)->exponentialDistributions([
        ['Grp' => '', 'Bucket' => 1020, 'Scale' => 1, 'ZeroCount' => 0, 'PositiveOffset' => 0, 'PositiveBucketCounts' => [4], 'NegativeOffset' => 0, 'NegativeBucketCounts' => []],
        ['Grp' => '', 'Bucket' => 1020, 'Scale' => 0, 'ZeroCount' => 0, 'PositiveOffset' => 2, 'PositiveBucketCounts' => [4], 'NegativeOffset' => 0, 'NegativeBucketCounts' => []],
    ], seriesContext(type: 'exponential_histogram'), cumulative: false);

    // At scale 0: 4 in (1, 2], 4 in (4, 8].
    expect(linePoints($result, 'p50')[0])->toBe(2.0)
        ->and(linePoints($result, 'p95')[0])->toEqualWithDelta(7.6, 1e-9);
});

it('charts a summary\'s stored quantiles, marked approximate', function () {
    $result = (new MetricSeriesBuilder)->summaries([
        ['Grp' => '', 'Bucket' => 1020, 'Q' => 0.99, 'V' => 200, 'W' => 1],
        ['Grp' => '', 'Bucket' => 1020, 'Q' => 0.5, 'V' => 40, 'W' => 1],
        ['Grp' => '', 'Bucket' => 1020, 'Q' => 0.0, 'V' => 3, 'W' => 1],
        ['Grp' => '', 'Bucket' => 1020, 'Q' => 0.999, 'V' => 480, 'W' => 1],
    ], seriesContext(type: 'summary'));

    expect($result['approximate'])->toBeTrue()
        ->and($result['kind'])->toBe('summary')
        ->and(array_column($result['series'], 'stat'))->toBe(['min', 'p50', 'p99', 'p99.9']);
});

it('reports no kind when nothing was reported in the window', function () {
    $result = (new MetricSeriesBuilder)->levels([], seriesContext(), 'avg', perSecond: false);

    expect($result['kind'])->toBeNull()
        ->and($result['series'])->toBe([])
        ->and($result['buckets'])->toHaveCount(3);
});
