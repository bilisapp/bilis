<?php

namespace App\Services\Metrics;

/**
 * Turns the rows {@see MetricQuery} reads into chart lines.
 *
 * Pure: no ClickHouse, no clock. Everything here is arithmetic the database
 * cannot do cheaply or cannot do at all — cumulative deltas per series with
 * reset detection (SCHEMA.md R15), merging histograms whose buckets only line
 * up after a rescale, reading percentiles off bucket counts — and all of it is
 * unit-tested against hand-computed values.
 *
 * Every shape ends the same way: values per group, per stat, per bucket start
 * (Unix seconds), plus a weight per group. {@see result()} keeps the ten
 * heaviest groups (R16), aligns every line to the window's buckets with nulls
 * where nothing was reported, and labels it.
 *
 * @phpstan-import-type Exponential from HistogramMath
 *
 * @phpstan-type Context array{metric: string, type: string, unit: string, grouped: bool, interval: int, starts: list<int>, readFrom: int, droppedSeries: int}
 * @phpstan-type Values array<string, array<string, array<int, float>>>
 * @phpstan-type Result array{metric: string|null, type: string|null, kind: string|null, unit: string, intervalSeconds: int, buckets: list<string>, series: list<array{label: string, group: string|null, stat: string, points: list<float|null>}>, truncatedGroups: int, droppedSeries: int, approximate: bool, unavailable: bool}
 */
class MetricSeriesBuilder
{
    /**
     * The most groups one chart draws; the rest are counted, not drawn.
     */
    public const MAX_GROUPS = 10;

    /**
     * The percentiles a distribution is charted at.
     */
    public const PERCENTILES = ['p50' => 0.5, 'p95' => 0.95, 'p99' => 0.99];

    /**
     * A chart with nothing on it.
     *
     * @return Result
     */
    public function empty(MetricFilters $filters, bool $unavailable = false): array
    {
        return [
            'metric' => $filters->metric,
            'type' => null,
            'kind' => null,
            'unit' => '',
            'intervalSeconds' => 0,
            'buckets' => [],
            'series' => [],
            'truncatedGroups' => 0,
            'droppedSeries' => 0,
            'approximate' => false,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * A level per group — a gauge, or an up-down counter — already combined
     * across series by SQL, or a delta counter's sum turned into a rate.
     *
     * Rows: `Bucket` (Unix seconds), `Grp`, `V`, `W` (points or series behind it).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Context  $context
     * @return Result
     */
    public function levels(array $rows, array $context, string $stat, bool $perSecond): array
    {
        $values = [];
        $weights = [];

        foreach ($rows as $row) {
            $group = (string) ($row['Grp'] ?? '');
            $value = (float) ($row['V'] ?? 0);

            $values[$group][$stat][(int) ($row['Bucket'] ?? 0)] = $perSecond ? $value / $context['interval'] : $value;
            $weights[$group] = ($weights[$group] ?? 0) + (float) ($row['W'] ?? 1);
        }

        return $this->result($context, $perSecond ? 'rate' : 'value', $values, $weights);
    }

    /**
     * A cumulative counter as a rate: per-series deltas, summed per group.
     *
     * Rows: `S` (series key), `Grp`, `Bucket`, `V` (the series' last value in
     * that bucket), `At` (that point's time) and `Start` (its StartTimeUnix),
     * both Unix seconds, ordered by series then bucket. The read begins one
     * bucket before the window so the first bucket has a baseline.
     *
     * Each increase is divided by the seconds that actually passed between the
     * two points, not by the bucket width: a series exported every minute read
     * into 15-second buckets would otherwise show four times its real rate.
     * A group's rate is the sum of its series' rates.
     *
     * A drop in value or a new start time is a reset: the new value is the
     * whole increase since, never a negative spike. A series whose very first
     * point started counting inside the read is counted from its start; one
     * that started before it only sets the baseline.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Context  $context
     * @return Result
     */
    public function cumulativeRates(array $rows, array $context): array
    {
        $values = [];
        $weights = [];
        $previous = [];

        foreach ($rows as $row) {
            $series = (string) ($row['S'] ?? '');
            $group = (string) ($row['Grp'] ?? '');
            $bucket = (int) ($row['Bucket'] ?? 0);
            $value = (float) ($row['V'] ?? 0);
            $start = (int) ($row['Start'] ?? 0);
            $at = (int) ($row['At'] ?? $bucket);

            $prior = $previous[$series] ?? null;
            $previous[$series] = ['value' => $value, 'start' => $start, 'at' => $at];

            if ($prior === null) {
                [$delta, $since] = $start > 0 && $start >= $context['readFrom'] ? [$value, $start] : [null, $at];
            } elseif ($start !== $prior['start'] || $value < $prior['value']) {
                [$delta, $since] = [$value, max($prior['at'], $start)];
            } else {
                [$delta, $since] = [$value - $prior['value'], $prior['at']];
            }

            if ($delta === null || $bucket < ($context['starts'][0] ?? PHP_INT_MIN)) {
                continue;
            }

            $elapsed = $at > $since ? $at - $since : $context['interval'];

            $values[$group]['rate'][$bucket] = ($values[$group]['rate'][$bucket] ?? 0.0) + $delta / $elapsed;
            $weights[$group] = ($weights[$group] ?? 0) + $delta;
        }

        return $this->result($context, 'rate', $values, $weights);
    }

    /**
     * Explicit-bucket histograms as percentiles.
     *
     * Delta rows (`$cumulative` false): `Grp`, `Bucket`, `Bounds`, `Counts`,
     * `C` — already summed per bucket and bound set by SQL. Cumulative rows
     * add `S` and `Start` and are the series' last point per bucket, ordered
     * by series then bucket; they are differenced here first.
     *
     * Only one bound set can be merged: the one carrying the most data. Series
     * with other bounds are left out and counted in `droppedSeries`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Context  $context
     * @return Result
     */
    public function explicitDistributions(array $rows, array $context, bool $cumulative): array
    {
        $dominant = $this->dominantBounds($rows);
        $counts = [];
        $boundsByGroup = [];
        $weights = [];
        $dropped = [];
        $previous = [];

        foreach ($rows as $row) {
            $bounds = self::floats($row['Bounds'] ?? []);
            $key = json_encode($bounds);
            $series = (string) ($row['S'] ?? '');

            if ($key !== $dominant) {
                $dropped[$cumulative ? $series : $key.'|'.($row['Grp'] ?? '')] = true;

                continue;
            }

            $group = (string) ($row['Grp'] ?? '');
            $bucket = (int) ($row['Bucket'] ?? 0);
            $current = self::floats($row['Counts'] ?? []);

            if ($cumulative) {
                $start = (int) ($row['Start'] ?? 0);
                $prior = $previous[$series] ?? null;
                $previous[$series] = ['counts' => $current, 'start' => $start];

                if ($prior === null) {
                    $delta = $start > 0 && $start >= $context['readFrom'] ? $current : null;
                } else {
                    $delta = $start !== $prior['start'] ? $current : (HistogramMath::subtractCounts($current, $prior['counts']) ?? $current);
                }
            } else {
                $delta = $current;
            }

            if ($delta === null || $bucket < ($context['starts'][0] ?? PHP_INT_MIN)) {
                continue;
            }

            $counts[$group][$bucket] = HistogramMath::addCounts($counts[$group][$bucket] ?? [], $delta);
            $boundsByGroup[$group] = $bounds;
            $weights[$group] = ($weights[$group] ?? 0) + array_sum($delta);
        }

        $values = [];

        foreach ($counts as $group => $buckets) {
            foreach ($buckets as $bucket => $bucketCounts) {
                $this->percentiles($values, $group, $bucket, HistogramMath::explicitIntervals($boundsByGroup[$group], $bucketCounts));
            }
        }

        $context['droppedSeries'] += count($dropped);

        return $this->result($context, 'distribution', $values, $weights);
    }

    /**
     * Base-2 exponential histograms as percentiles.
     *
     * Rows carry `Grp`, `Bucket`, `Scale`, `ZeroCount`, `PositiveOffset`,
     * `PositiveBucketCounts`, `NegativeOffset`, `NegativeBucketCounts`; delta
     * rows are raw points, cumulative rows add `S` and `Start` and are the
     * series' last point per bucket. Points at different scales are merged at
     * the lower one, which is always exact.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Context  $context
     * @return Result
     */
    public function exponentialDistributions(array $rows, array $context, bool $cumulative): array
    {
        /** @var array<string, array<int, Exponential>> $merged */
        $merged = [];
        $weights = [];
        $previous = [];

        foreach ($rows as $row) {
            $group = (string) ($row['Grp'] ?? '');
            $bucket = (int) ($row['Bucket'] ?? 0);
            $current = HistogramMath::exponential(
                (int) ($row['Scale'] ?? 0),
                (float) ($row['ZeroCount'] ?? 0),
                (int) ($row['PositiveOffset'] ?? 0),
                self::floats($row['PositiveBucketCounts'] ?? []),
                (int) ($row['NegativeOffset'] ?? 0),
                self::floats($row['NegativeBucketCounts'] ?? []),
            );

            if ($cumulative) {
                $series = (string) ($row['S'] ?? '');
                $start = (int) ($row['Start'] ?? 0);
                $prior = $previous[$series] ?? null;
                $previous[$series] = ['histogram' => $current, 'start' => $start];

                if ($prior === null) {
                    $delta = $start > 0 && $start >= $context['readFrom'] ? $current : null;
                } else {
                    $delta = $start !== $prior['start'] ? $current : (HistogramMath::subtractExponential($current, $prior['histogram']) ?? $current);
                }
            } else {
                $delta = $current;
            }

            if ($delta === null || $bucket < ($context['starts'][0] ?? PHP_INT_MIN)) {
                continue;
            }

            $merged[$group][$bucket] = isset($merged[$group][$bucket])
                ? HistogramMath::mergeExponential($merged[$group][$bucket], $delta)
                : $delta;
            $weights[$group] = ($weights[$group] ?? 0) + $delta['zero'] + array_sum($delta['positive']) + array_sum($delta['negative']);
        }

        $values = [];

        foreach ($merged as $group => $buckets) {
            foreach ($buckets as $bucket => $histogram) {
                $this->percentiles($values, $group, $bucket, HistogramMath::exponentialIntervals($histogram));
            }
        }

        return $this->result($context, 'distribution', $values, $weights);
    }

    /**
     * Summaries: each stored quantile averaged across the group's series.
     *
     * A summary's quantiles were computed by the client over its own
     * observations and cannot be merged exactly, so the result says
     * `approximate`. Rows: `Grp`, `Bucket`, `Q`, `V`, `W`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Context  $context
     * @return Result
     */
    public function summaries(array $rows, array $context): array
    {
        $values = [];
        $weights = [];

        foreach ($rows as $row) {
            $group = (string) ($row['Grp'] ?? '');
            $stat = self::quantileStat((float) ($row['Q'] ?? 0));

            $values[$group][$stat][(int) ($row['Bucket'] ?? 0)] = (float) ($row['V'] ?? 0);
            $weights[$group] = ($weights[$group] ?? 0) + (float) ($row['W'] ?? 1);
        }

        $result = $this->result($context, 'summary', $values, $weights);
        $result['approximate'] = true;

        return $result;
    }

    /**
     * The chart: the heaviest groups, every line aligned to the window.
     *
     * @param  Context  $context
     * @param  Values  $values
     * @param  array<string, float|int>  $weights
     * @return Result
     */
    public function result(array $context, string $kind, array $values, array $weights): array
    {
        arsort($weights);
        $groups = array_slice(array_map(strval(...), array_keys($weights)), 0, self::MAX_GROUPS);

        $series = [];

        foreach ($groups as $group) {
            $stats = $values[$group] ?? [];
            uksort($stats, self::statOrder(...));

            foreach ($stats as $stat => $points) {
                $series[] = [
                    'label' => $this->label($context, $kind, $group, $stat),
                    'group' => $context['grouped'] ? $group : null,
                    'stat' => $stat,
                    'points' => array_map(
                        fn (int $start): ?float => isset($points[$start]) ? round($points[$start], 6) : null,
                        $context['starts'],
                    ),
                ];
            }
        }

        return [
            'metric' => $context['metric'],
            'type' => $context['type'],
            'kind' => $series === [] ? null : $kind,
            'unit' => $context['unit'],
            'intervalSeconds' => $context['interval'],
            'buckets' => array_map(fn (int $start): string => gmdate('Y-m-d H:i:s', $start), $context['starts']),
            'series' => $series,
            'truncatedGroups' => max(0, count($weights) - self::MAX_GROUPS),
            'droppedSeries' => $context['droppedSeries'],
            'approximate' => false,
            'unavailable' => false,
        ];
    }

    /**
     * Read the charted percentiles off one merged bucket.
     *
     * @param  Values  $values
     * @param  list<array{0: float, 1: float, 2: float}>  $intervals
     */
    private function percentiles(array &$values, string $group, int $bucket, array $intervals): void
    {
        foreach (self::PERCENTILES as $stat => $quantile) {
            $value = HistogramMath::quantile($intervals, $quantile);

            if ($value !== null) {
                $values[$group][$stat][$bucket] = $value;
            }
        }
    }

    /**
     * The bound set carrying the most observations, as its JSON encoding.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function dominantBounds(array $rows): ?string
    {
        $totals = [];

        foreach ($rows as $row) {
            $key = (string) json_encode(self::floats($row['Bounds'] ?? []));
            $totals[$key] = ($totals[$key] ?? 0) + (float) ($row['C'] ?? 0) + 1;
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);

        return (string) array_key_first($totals);
    }

    /**
     * @param  Context  $context
     */
    private function label(array $context, string $kind, string $group, string $stat): string
    {
        if ($context['grouped']) {
            return $group === '' ? '(none)' : $group;
        }

        return in_array($kind, ['distribution', 'summary'], true) ? $stat : $context['metric'];
    }

    /**
     * A summary quantile's stat name: `p50`, `p99.9`, `min` for 0, `max` for 1.
     */
    private static function quantileStat(float $quantile): string
    {
        return match (true) {
            $quantile <= 0.0 => 'min',
            $quantile >= 1.0 => 'max',
            default => 'p'.rtrim(rtrim(number_format($quantile * 100, 3, '.', ''), '0'), '.'),
        };
    }

    /**
     * Percentiles in ascending order, `min` first and `max` last.
     */
    private static function statOrder(string $a, string $b): int
    {
        $rank = fn (string $stat): float => match (true) {
            $stat === 'min' => -1.0,
            $stat === 'max' => 1000.0,
            str_starts_with($stat, 'p') && is_numeric(substr($stat, 1)) => (float) substr($stat, 1),
            default => 0.0,
        };

        return $rank($a) <=> $rank($b);
    }

    /**
     * @return list<float>
     */
    private static function floats(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(floatval(...), $values)) : [];
    }
}
