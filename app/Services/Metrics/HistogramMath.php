<?php

namespace App\Services\Metrics;

/**
 * Bucket arithmetic for explicit-bucket and base-2 exponential histograms.
 *
 * Both shapes are reduced to the same thing before a percentile is read off
 * them: an ordered list of `[lower, upper, count]` intervals. A percentile is
 * then a walk to the interval holding the rank and a linear interpolation
 * inside it — the same estimate Prometheus' `histogram_quantile` makes, and
 * with the same limit: it can never be more precise than the buckets are.
 *
 * Exponential buckets are indexed: at scale `s`, `base = 2^(2^-s)` and index
 * `i` covers `(base^i, base^(i+1)]` (negated for the negative side). Halving
 * the resolution maps index `i` to `i >> 1` — arithmetic shift, which floors
 * negative indices correctly — so two points at different scales are merged by
 * bringing both down to the lower one first.
 *
 * @phpstan-type Interval array{0: float, 1: float, 2: float}
 * @phpstan-type Exponential array{scale: int, zero: float, positive: array<int, float>, negative: array<int, float>}
 */
final class HistogramMath
{
    /**
     * The intervals of an explicit-bucket histogram.
     *
     * N bounds make N + 1 buckets: `(-inf, b0]`, `(b0, b1]` … `(bN-1, +inf)`.
     * The open ends cannot be interpolated into, so the first bucket is read as
     * starting at 0 (or at its bound, if that is negative) and the overflow
     * bucket as the single point of the last bound — the usual convention.
     *
     * @param  list<float>  $bounds
     * @param  list<float>  $counts
     * @return list<Interval>
     */
    public static function explicitIntervals(array $bounds, array $counts): array
    {
        $intervals = [];
        $last = count($bounds) - 1;

        foreach ($counts as $index => $count) {
            if ($bounds === []) {
                $intervals[] = [0.0, 0.0, $count];

                continue;
            }

            $intervals[] = match (true) {
                $index === 0 => [min(0.0, $bounds[0]), $bounds[0], $count],
                $index > $last => [$bounds[$last], $bounds[$last], $count],
                default => [$bounds[$index - 1], $bounds[$index], $count],
            };
        }

        return $intervals;
    }

    /**
     * The intervals of an exponential histogram, most negative first.
     *
     * @param  Exponential  $histogram
     * @return list<Interval>
     */
    public static function exponentialIntervals(array $histogram): array
    {
        $base = 2.0 ** (2.0 ** -$histogram['scale']);
        $intervals = [];

        $negative = $histogram['negative'];
        krsort($negative);

        foreach ($negative as $index => $count) {
            $intervals[] = [-($base ** ($index + 1)), -($base ** $index), $count];
        }

        if ($histogram['zero'] > 0) {
            $intervals[] = [0.0, 0.0, $histogram['zero']];
        }

        $positive = $histogram['positive'];
        ksort($positive);

        foreach ($positive as $index => $count) {
            $intervals[] = [$base ** $index, $base ** ($index + 1), $count];
        }

        return $intervals;
    }

    /**
     * The value at quantile `$q` (0–1), or null for an empty distribution.
     *
     * @param  list<Interval>  $intervals  In ascending value order.
     */
    public static function quantile(array $intervals, float $q): ?float
    {
        $total = array_sum(array_column($intervals, 2));

        if ($total <= 0) {
            return null;
        }

        $rank = $q * $total;
        $seen = 0.0;

        foreach ($intervals as [$lower, $upper, $count]) {
            if ($count <= 0) {
                continue;
            }

            if ($seen + $count >= $rank) {
                $fraction = ($rank - $seen) / $count;

                return $lower + ($upper - $lower) * max(0.0, min(1.0, $fraction));
            }

            $seen += $count;
        }

        $lastNonEmpty = array_values(array_filter($intervals, fn (array $interval): bool => $interval[2] > 0));

        return $lastNonEmpty === [] ? null : $lastNonEmpty[count($lastNonEmpty) - 1][1];
    }

    /**
     * An exponential point, as read from a row, in the indexed form.
     *
     * @param  list<int|float>  $positiveCounts
     * @param  list<int|float>  $negativeCounts
     * @return Exponential
     */
    public static function exponential(int $scale, float $zero, int $positiveOffset, array $positiveCounts, int $negativeOffset, array $negativeCounts): array
    {
        return [
            'scale' => $scale,
            'zero' => $zero,
            'positive' => self::indexed($positiveOffset, $positiveCounts),
            'negative' => self::indexed($negativeOffset, $negativeCounts),
        ];
    }

    /**
     * The same distribution at a lower (or equal) scale.
     *
     * @param  Exponential  $histogram
     * @return Exponential
     */
    public static function downscale(array $histogram, int $scale): array
    {
        $shift = $histogram['scale'] - $scale;

        if ($shift <= 0) {
            return $histogram;
        }

        return [
            'scale' => $scale,
            'zero' => $histogram['zero'],
            'positive' => self::shifted($histogram['positive'], $shift),
            'negative' => self::shifted($histogram['negative'], $shift),
        ];
    }

    /**
     * Two exponential distributions added together, at the lower scale.
     *
     * @param  Exponential  $a
     * @param  Exponential  $b
     * @return Exponential
     */
    public static function mergeExponential(array $a, array $b): array
    {
        $scale = min($a['scale'], $b['scale']);
        $a = self::downscale($a, $scale);
        $b = self::downscale($b, $scale);

        return [
            'scale' => $scale,
            'zero' => $a['zero'] + $b['zero'],
            'positive' => self::add($a['positive'], $b['positive']),
            'negative' => self::add($a['negative'], $b['negative']),
        ];
    }

    /**
     * What a cumulative exponential point added since the previous one, or
     * null when a count went down — the series was reset.
     *
     * @param  Exponential  $current
     * @param  Exponential  $previous
     * @return Exponential|null
     */
    public static function subtractExponential(array $current, array $previous): ?array
    {
        $scale = min($current['scale'], $previous['scale']);
        $current = self::downscale($current, $scale);
        $previous = self::downscale($previous, $scale);

        $positive = self::subtract($current['positive'], $previous['positive']);
        $negative = self::subtract($current['negative'], $previous['negative']);
        $zero = $current['zero'] - $previous['zero'];

        if ($positive === null || $negative === null || $zero < 0) {
            return null;
        }

        return ['scale' => $scale, 'zero' => $zero, 'positive' => $positive, 'negative' => $negative];
    }

    /**
     * Element-wise difference of two explicit bucket-count lists, or null on a
     * reset (a count went down, or the lists no longer line up).
     *
     * @param  list<float>  $current
     * @param  list<float>  $previous
     * @return list<float>|null
     */
    public static function subtractCounts(array $current, array $previous): ?array
    {
        if (count($current) !== count($previous)) {
            return null;
        }

        $difference = [];

        foreach ($current as $index => $count) {
            $delta = $count - $previous[$index];

            if ($delta < 0) {
                return null;
            }

            $difference[] = $delta;
        }

        return $difference;
    }

    /**
     * Element-wise sum of two explicit bucket-count lists of the same length.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     * @return list<float>
     */
    public static function addCounts(array $a, array $b): array
    {
        if ($a === []) {
            return $b;
        }

        return array_map(fn (float $x, float $y): float => $x + $y, $a, $b);
    }

    /**
     * @param  list<int|float>  $counts
     * @return array<int, float>
     */
    private static function indexed(int $offset, array $counts): array
    {
        $indexed = [];

        foreach ($counts as $position => $count) {
            if ($count > 0) {
                $indexed[$offset + $position] = (float) $count;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<int, float>  $buckets
     * @return array<int, float>
     */
    private static function shifted(array $buckets, int $shift): array
    {
        $shifted = [];

        foreach ($buckets as $index => $count) {
            $target = $index >> $shift;
            $shifted[$target] = ($shifted[$target] ?? 0.0) + $count;
        }

        return $shifted;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private static function add(array $a, array $b): array
    {
        foreach ($b as $index => $count) {
            $a[$index] = ($a[$index] ?? 0.0) + $count;
        }

        return $a;
    }

    /**
     * @param  array<int, float>  $current
     * @param  array<int, float>  $previous
     * @return array<int, float>|null
     */
    private static function subtract(array $current, array $previous): ?array
    {
        foreach ($previous as $index => $count) {
            $remaining = ($current[$index] ?? 0.0) - $count;

            if ($remaining < 0) {
                return null;
            }

            $current[$index] = $remaining;
        }

        return array_filter($current, fn (float $count): bool => $count > 0);
    }
}
