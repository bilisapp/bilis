<?php

namespace App\Services\Metrics;

/**
 * The fixed set of charts the Hosts tab draws for one machine.
 *
 * Not a dashboard: nobody edits this list. Each chart names candidate
 * metrics in preference order — the scraper's `*.utilization` gauges when the
 * agent enabled them, the default counters otherwise — and the first one the
 * catalog holds is charted through {@see MetricQuery::series()}, filtered to
 * the host. No SQL lives here; the class only decides what to ask for and how
 * to label the answer.
 *
 * A candidate may rescale the result for display: a `1` ratio becomes a
 * percentage, a CPU-time rate (seconds or nanoseconds per second) becomes
 * cores busy. {@see present()} is pure and unit-tested.
 *
 * @phpstan-import-type Result from MetricSeriesBuilder
 *
 * @phpstan-type Candidate array{metric: string, groupBy: string|null, where: array<string, string>, agg: string, scale: float, unit: string|null}
 * @phpstan-type Chart array{id: string, title: string, containers: bool, candidates: list<Candidate>}
 */
class HostCharts
{
    /**
     * Every chart, in page order. `containers` charts are drawn only for a
     * host that reported containers.
     *
     * @return list<Chart>
     */
    public static function definitions(): array
    {
        $percent = [100.0, '%'];
        $plain = [1.0, null];
        $cores = fn (float $scale): array => [$scale, '{core}'];
        $candidate = self::candidate(...);

        return [
            ['id' => 'cpu', 'title' => 'CPU', 'containers' => false, 'candidates' => [
                $candidate('system.cpu.utilization', 'state', 'avg', $percent),
                $candidate('system.cpu.time', 'state', 'avg', $cores(1.0)),
            ]],
            ['id' => 'load', 'title' => 'Load average (1m)', 'containers' => false, 'candidates' => [
                $candidate('system.cpu.load_average.1m', null, 'avg', $plain),
            ]],
            ['id' => 'memory', 'title' => 'Memory', 'containers' => false, 'candidates' => [
                $candidate('system.memory.utilization', 'state', 'avg', $percent),
                $candidate('system.memory.usage', 'state', 'avg', $plain),
            ]],
            ['id' => 'disk-space', 'title' => 'Disk space used', 'containers' => false, 'candidates' => [
                $candidate('system.filesystem.utilization', 'mountpoint', 'max', $percent),
                $candidate('system.filesystem.usage', 'mountpoint', 'max', $plain, ['state' => 'used']),
            ]],
            ['id' => 'disk-io', 'title' => 'Disk I/O', 'containers' => false, 'candidates' => [
                $candidate('system.disk.io', 'direction', 'avg', $plain),
            ]],
            ['id' => 'network', 'title' => 'Network', 'containers' => false, 'candidates' => [
                $candidate('system.network.io', 'direction', 'avg', $plain),
            ]],
            ['id' => 'container-cpu', 'title' => 'Container CPU', 'containers' => true, 'candidates' => [
                $candidate('container.cpu.usage.total', 'container.name', 'avg', $cores(1e-9)),
            ]],
            ['id' => 'container-memory', 'title' => 'Container memory', 'containers' => true, 'candidates' => [
                $candidate('container.memory.usage.total', 'container.name', 'avg', $plain),
            ]],
        ];
    }

    /**
     * One way of drawing a chart.
     *
     * @param  array{0: float, 1: string|null}  $display  scale and unit, see {@see present()}
     * @param  array<string, string>  $where
     * @return Candidate
     */
    private static function candidate(string $metric, ?string $groupBy, string $agg, array $display, array $where = []): array
    {
        return [
            'metric' => $metric,
            'groupBy' => $groupBy,
            'where' => $where,
            'agg' => $agg,
            'scale' => $display[0],
            'unit' => $display[1],
        ];
    }

    /**
     * The charts for one host, each with its series.
     *
     * A chart none of whose metrics is in the catalog is left out rather than
     * drawn empty: a host without a disk scraper has no Disk I/O to show. The
     * first candidate with data for this host wins.
     *
     * @param  list<string>  $projectIds
     * @return array{charts: list<array{id: string, title: string, metric: string, groupBy: string|null, where: array<string, string>, agg: string, series: Result}>, unavailable: bool}
     */
    public static function charts(MetricQuery $metrics, array $projectIds, MetricFilters $window, string $host, bool $withContainers): array
    {
        $catalog = $metrics->catalog($projectIds, $window);

        if ($catalog['unavailable']) {
            return ['charts' => [], 'unavailable' => true];
        }

        $present = array_flip(array_map(fn (array $entry): string => $entry['name'], $catalog['metrics']));
        $charts = [];

        foreach (self::definitions() as $chart) {
            if ($chart['containers'] && ! $withContainers) {
                continue;
            }

            $drawn = null;

            foreach ($chart['candidates'] as $candidate) {
                if (! isset($present[$candidate['metric']])) {
                    continue;
                }

                $where = [...$candidate['where'], 'host.name' => $host];

                $series = $metrics->series($projectIds, new MetricFilters(
                    project: $window->project,
                    metric: $candidate['metric'],
                    where: $where,
                    groupBy: $candidate['groupBy'],
                    aggregation: $candidate['agg'],
                    from: $window->from,
                    to: $window->to,
                ));

                $drawn = [
                    'id' => $chart['id'],
                    'title' => $chart['title'],
                    'metric' => $candidate['metric'],
                    'groupBy' => $candidate['groupBy'],
                    'where' => $where,
                    'agg' => $candidate['agg'],
                    'series' => self::present($series, $candidate['scale'], $candidate['unit']),
                ];

                // The catalog spans every host: the preferred metric may come
                // from another machine. An empty answer falls through.
                if ($series['kind'] !== null) {
                    break;
                }
            }

            if ($drawn !== null) {
                $charts[] = $drawn;
            }
        }

        return ['charts' => $charts, 'unavailable' => false];
    }

    /**
     * Rescale a chart for display: every point multiplied by `$scale` and the
     * unit replaced. A replaced unit is a level — "45 %", "1.5 cores" — so a
     * rate becomes a value and loses its "/s".
     *
     * @param  Result  $result
     * @return Result
     */
    public static function present(array $result, float $scale, ?string $unit): array
    {
        if ($unit === null) {
            return $result;
        }

        $result['unit'] = $unit;

        if ($result['kind'] === 'rate') {
            $result['kind'] = 'value';
        }

        foreach ($result['series'] as $index => $series) {
            $result['series'][$index]['points'] = array_map(
                fn (?float $point): ?float => $point === null ? null : round($point * $scale, 6),
                $series['points'],
            );
        }

        return $result;
    }
}
