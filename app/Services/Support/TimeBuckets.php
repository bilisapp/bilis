<?php

namespace App\Services\Support;

use DateTimeInterface;

/**
 * The one ladder of bucket widths every time-bucketed chart chooses from.
 *
 * The log histogram, the trace histogram and the metric explorer sit on
 * sibling pages, and a "1h" window must cut into the same buckets on each, so
 * the choice is made here once rather than restated per query service.
 */
final class TimeBuckets
{
    /**
     * The widths a bucket may take, in seconds.
     */
    public const INTERVALS = [1, 5, 15, 30, 60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    /**
     * The most buckets any chart draws; past the ladder the width grows instead.
     */
    public const MAX_BUCKETS = 240;

    /**
     * The narrowest width that cuts the window into at most `$target` buckets.
     */
    public static function interval(DateTimeInterface $from, DateTimeInterface $to, int $target): int
    {
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());

        foreach (self::INTERVALS as $interval) {
            if ((int) ceil($span / $interval) <= $target) {
                return $interval;
            }
        }

        return (int) max(self::INTERVALS[count(self::INTERVALS) - 1], (int) ceil($span / self::MAX_BUCKETS));
    }

    /**
     * Every bucket start across the window, as Unix seconds, aligned to the width.
     *
     * Aligned the way ClickHouse's `toStartOfInterval` aligns them, so a row
     * keyed by its bucket lands on one of these.
     *
     * @return list<int>
     */
    public static function starts(DateTimeInterface $from, DateTimeInterface $to, int $interval): array
    {
        $starts = [];
        $end = $to->getTimestamp();

        for ($at = intdiv($from->getTimestamp(), $interval) * $interval; $at <= $end && count($starts) < self::MAX_BUCKETS; $at += $interval) {
            $starts[] = $at;
        }

        return $starts;
    }
}
