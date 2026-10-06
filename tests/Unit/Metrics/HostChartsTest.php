<?php

use App\Services\Metrics\HostCharts;

/**
 * @return array<string, mixed>
 */
function hostChartResult(string $kind, string $unit, array $points): array
{
    return [
        'metric' => 'm', 'type' => 'gauge', 'kind' => $kind, 'unit' => $unit, 'intervalSeconds' => 60,
        'buckets' => ['2026-10-05 10:00:00', '2026-10-05 10:01:00'],
        'series' => [['label' => 'user', 'group' => 'user', 'stat' => 'avg', 'points' => $points]],
        'truncatedGroups' => 0, 'droppedSeries' => 0, 'approximate' => false, 'unavailable' => false,
    ];
}

it('turns a ratio into a percentage', function () {
    $result = HostCharts::present(hostChartResult('value', '1', [0.25, null]), 100.0, '%');

    expect($result['unit'])->toBe('%')
        ->and($result['kind'])->toBe('value')
        ->and($result['series'][0]['points'])->toBe([25.0, null]);
});

it('turns a nanosecond rate into cores busy, a level rather than a rate', function () {
    $result = HostCharts::present(hostChartResult('rate', 'ns', [500_000_000.0, 250_000_000.0]), 1e-9, '{core}');

    expect($result['unit'])->toBe('{core}')
        ->and($result['kind'])->toBe('value')
        ->and($result['series'][0]['points'])->toBe([0.5, 0.25]);
});

it('leaves a chart without a display unit untouched', function () {
    $result = hostChartResult('rate', 'By', [1024.0, 2048.0]);

    expect(HostCharts::present($result, 1.0, null))->toBe($result);
});

it('prefers the utilization gauges and falls back to the default counters', function () {
    $candidates = collect(HostCharts::definitions())->mapWithKeys(fn (array $chart): array => [
        $chart['id'] => array_column($chart['candidates'], 'metric'),
    ]);

    expect($candidates['cpu'])->toBe(['system.cpu.utilization', 'system.cpu.time'])
        ->and($candidates['memory'])->toBe(['system.memory.utilization', 'system.memory.usage'])
        ->and($candidates['disk-space'])->toBe(['system.filesystem.utilization', 'system.filesystem.usage']);
});
