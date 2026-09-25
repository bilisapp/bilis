<?php

namespace App\Services\Ingest;

/**
 * Formats OTLP nanosecond times for the metrics tables' `DateTime('UTC')` columns.
 *
 * The exporter declares metric time as `DateTime` — whole seconds, 32 bits —
 * not the `DateTime64(9)` logs and spans use, so the window a point may carry
 * is narrower too: 2000-01-01 up to, not including, 2106-01-01, where the type
 * runs out (SCHEMA.md R14). A value past that would be acked and then refused
 * by ClickHouse after the fact, taking its whole async block with it; a value
 * before 2000 is seconds or milliseconds sent as nanoseconds. Both are
 * rejected, never clamped.
 *
 * Seconds are taken by truncation, which is what the exporter's own
 * `time.Time` → `DateTime` conversion does.
 */
final class MetricTimestamp
{
    /**
     * 2000-01-01 00:00:00 UTC, the same floor `LogTimestamp` keeps.
     */
    public const MIN_SECONDS = LogTimestamp::MIN_SECONDS;

    /**
     * 2106-01-01 00:00:00 UTC; `DateTime` ends on 2106-02-07.
     */
    public const MAX_SECONDS_EXCLUSIVE = 4_291_747_200;

    /**
     * What `StartTimeUnix` and an exemplar time hold when the sender set none.
     */
    public const EPOCH = '1970-01-01 00:00:00';

    /**
     * A point's time, or null when it is absent or outside the storable window.
     */
    public static function fromNanos(mixed $value): ?string
    {
        $nanos = OtlpValues::nanosAsInt($value);

        if ($nanos === null) {
            return null;
        }

        $seconds = intdiv($nanos, 1_000_000_000);

        if ($seconds < self::MIN_SECONDS || $seconds >= self::MAX_SECONDS_EXCLUSIVE) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $seconds);
    }

    /**
     * An optional time — a start or an exemplar's — with the epoch standing in
     * for "not set", as the exporter writes it.
     *
     * Only the point's own time decides whether it is kept; a start time the
     * sender got wrong is information lost, not a reason to drop the value.
     */
    public static function optional(mixed $value): string
    {
        return self::fromNanos($value) ?? self::EPOCH;
    }
}
