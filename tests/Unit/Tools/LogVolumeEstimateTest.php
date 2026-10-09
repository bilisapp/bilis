<?php

use App\Services\Tools\LogVolumeEstimate;

it('turns a rate and a size into decimal gigabytes over a 30-day month', function () {
    $estimate = LogVolumeEstimate::fromInput(['eps' => '1000', 'size' => '500', 'retention' => '15', 'ratio' => '10']);

    expect($estimate->eventsPerDay())->toBe(86_400_000.0)
        ->and($estimate->gigabytesPerDay())->toBe(43.2)
        ->and($estimate->gigabytesPerMonth())->toEqualWithDelta(1296.0, 1e-9)
        ->and($estimate->retainedGigabytes())->toEqualWithDelta(648.0, 1e-9)
        ->and($estimate->diskGigabytes())->toEqualWithDelta(64.8, 1e-9);
});

it('prices ingest per GB and indexing per million events', function () {
    $estimate = LogVolumeEstimate::fromInput(['eps' => '1000', 'size' => '500', 'ingest' => '0.10', 'index' => '1.70']);

    // 1,296 GB × $0.10 + 2,592 M events × $1.70
    expect($estimate->ingestCostPerMonth())->toEqualWithDelta(129.6, 1e-9)
        ->and($estimate->indexCostPerMonth())->toEqualWithDelta(4406.4, 1e-9)
        ->and($estimate->costPerMonth())->toEqualWithDelta(4536.0, 1e-9)
        ->and($estimate->costPerYear())->toEqualWithDelta(54_432.0, 1e-6);
});

it('falls back to the defaults for missing or non-numeric input', function () {
    $estimate = LogVolumeEstimate::fromInput(['eps' => 'lots', 'size' => ['x']]);

    expect($estimate->eventsPerSecond)->toBe(LogVolumeEstimate::DEFAULTS['eps'])
        ->and($estimate->averageEventBytes)->toBe(LogVolumeEstimate::DEFAULTS['size'])
        ->and($estimate->ingestPricePerGb)->toBe(LogVolumeEstimate::DEFAULTS['ingest']);
});

it('clamps rather than rejects out-of-range input', function () {
    $estimate = LogVolumeEstimate::fromInput(['eps' => '-5', 'size' => '0', 'retention' => '99999', 'ratio' => '0', 'index' => '1e400']);

    expect($estimate->eventsPerSecond)->toBe(0.0)
        ->and($estimate->averageEventBytes)->toBe(1)
        ->and($estimate->retentionDays)->toBe(3650)
        ->and($estimate->compressionRatio)->toBe(1.0)
        ->and($estimate->indexPricePerMillion)->toBe(100.0);
});

it('accepts thousands separators', function () {
    expect(LogVolumeEstimate::fromInput(['eps' => '12,500'])->eventsPerSecond)->toBe(12_500.0);
});
