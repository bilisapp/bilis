<?php

use App\Services\Metrics\HistogramMath;

it('lays explicit buckets out as intervals, open ends pinned', function () {
    expect(HistogramMath::explicitIntervals([0.1, 0.5, 1.0], [1.0, 2.0, 3.0, 4.0]))->toBe([
        [0.0, 0.1, 1.0],
        [0.1, 0.5, 2.0],
        [0.5, 1.0, 3.0],
        [1.0, 1.0, 4.0],
    ]);
});

it('interpolates a percentile inside the bucket that holds its rank', function () {
    // 10 observations in (0, 0.1], 10 in (0.1, 0.5].
    $intervals = HistogramMath::explicitIntervals([0.1, 0.5, 1.0], [10.0, 10.0, 0.0, 0.0]);

    expect(HistogramMath::quantile($intervals, 0.5))->toBe(0.1)
        ->and(HistogramMath::quantile($intervals, 0.95))->toEqualWithDelta(0.46, 1e-9)
        ->and(HistogramMath::quantile($intervals, 0.99))->toEqualWithDelta(0.492, 1e-9)
        ->and(HistogramMath::quantile([], 0.5))->toBeNull()
        ->and(HistogramMath::quantile([[0.0, 1.0, 0.0]], 0.5))->toBeNull();
});

it('reads an overflow-bucket percentile as the last bound', function () {
    expect(HistogramMath::quantile(HistogramMath::explicitIntervals([1.0], [0.0, 5.0]), 0.99))->toBe(1.0);
});

it('lays exponential buckets out by powers of the base, negatives first', function () {
    // Scale 0: base 2. Index 0 is (1, 2], index 2 is (4, 8]; negative index 0 is (-2, -1].
    $histogram = HistogramMath::exponential(0, 1.0, 0, [4, 0, 4], 0, [2]);

    expect(HistogramMath::exponentialIntervals($histogram))->toBe([
        [-2.0, -1.0, 2.0],
        [0.0, 0.0, 1.0],
        [1.0, 2.0, 4.0],
        [4.0, 8.0, 4.0],
    ]);
});

it('merges exponential points at different scales at the lower one', function () {
    // Scale 1 index 2 is (2, 2.83]; at scale 0 it becomes index 1, (2, 4].
    $fine = HistogramMath::exponential(1, 0.0, 2, [3], 0, []);
    $coarse = HistogramMath::exponential(0, 0.0, 1, [2], 0, []);

    $merged = HistogramMath::mergeExponential($fine, $coarse);

    expect($merged['scale'])->toBe(0)
        ->and($merged['positive'])->toBe([1 => 5.0]);
});

it('floors negative indices when downscaling', function () {
    expect(HistogramMath::downscale(HistogramMath::exponential(2, 0.0, -3, [1], 0, []), 1)['positive'])->toBe([-2 => 1.0]);
});

it('differences cumulative points, and calls a drop a reset', function () {
    expect(HistogramMath::subtractCounts([5.0, 7.0], [2.0, 3.0]))->toBe([3.0, 4.0])
        ->and(HistogramMath::subtractCounts([1.0, 7.0], [2.0, 3.0]))->toBeNull()
        ->and(HistogramMath::subtractCounts([1.0], [1.0, 2.0]))->toBeNull();

    $later = HistogramMath::exponential(0, 2.0, 0, [5, 0, 3], 0, []);
    $earlier = HistogramMath::exponential(0, 1.0, 0, [2], 0, []);

    expect(HistogramMath::subtractExponential($later, $earlier))->toBe([
        'scale' => 0,
        'zero' => 1.0,
        'positive' => [0 => 3.0, 2 => 3.0],
        'negative' => [],
    ])->and(HistogramMath::subtractExponential($earlier, $later))->toBeNull();
});
