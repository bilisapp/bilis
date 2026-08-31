<?php

namespace App\Services\Ingest;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Formatting helpers for ClickHouse `DateTime64(9)` columns.
 *
 * Nanosecond values are formatted with string arithmetic so no precision is
 * lost by round tripping them through a float or a PHP date object.
 */
class LogTimestamp
{
    /**
     * The number of fractional digits a `DateTime64(9)` column holds.
     */
    private const PRECISION = 9;

    /**
     * The earliest unix second a stored timestamp may carry: 2000-01-01T00:00:00Z.
     *
     * Nothing Bilis ingests happened before then. What does arrive below this
     * line is a unit mistake — seconds or milliseconds sent as nanoseconds land
     * in January 1970 — and a row dated 1970 is expired by the very next TTL
     * merge, so it was never really stored. Refusing it lets the mapper fall
     * back or count a rejection instead.
     */
    public const MIN_SECONDS = 946_684_800;

    /**
     * The first unix second that is out of range: 2261-01-01T00:00:00Z.
     *
     * `DateTime64(9)` tops out in April 2262. A larger value does not clamp
     * server-side: ClickHouse refuses the row, and with `wait_for_async_insert=0`
     * it does so after the insert has already been acked — the whole block is
     * lost silently. Bounded here so an over-long digit string that PHP's `(int)`
     * cast saturates into the year 292277026596 never reaches the table.
     */
    public const MAX_SECONDS_EXCLUSIVE = 9_183_110_400;

    /**
     * Format a unix nanosecond timestamp, given as an integer or a string.
     *
     * Null when the value is not a digit string or falls outside the range a
     * `DateTime64(9)` column can hold and Bilis is prepared to keep
     * ({@see MIN_SECONDS}, {@see MAX_SECONDS_EXCLUSIVE}).
     */
    public static function fromNanos(int|string $nanos): ?string
    {
        $digits = ltrim((string) $nanos);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        $digits = str_pad($digits, self::PRECISION + 1, '0', STR_PAD_LEFT);

        $seconds = ltrim(substr($digits, 0, -self::PRECISION), '0') ?: '0';
        $fraction = substr($digits, -self::PRECISION);

        // Compared as strings first: a digit string too long for an int would
        // saturate under a cast, and a saturated value compares as in range.
        if (strlen($seconds) > strlen((string)self::MAX_SECONDS_EXCLUSIVE) || !self::inRange((int)$seconds)) {
            return null;
        }

        return self::format((int) $seconds, $fraction);
    }

    /**
     * Whether a unix second lies inside the window a stored timestamp may carry.
     */
    public static function inRange(int $seconds): bool
    {
        return $seconds >= self::MIN_SECONDS && $seconds < self::MAX_SECONDS_EXCLUSIVE;
    }

    /**
     * Format an arbitrary user supplied timestamp value.
     *
     * Accepts ISO 8601 strings, unix seconds, milliseconds, microseconds and
     * nanoseconds. Returns null when the value cannot be understood, or names
     * a moment outside the window the table keeps ({@see fromNanos}).
     */
    public static function parse(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return self::inRange($value->getTimestamp()) ? self::fromDate(Carbon::instance($value)) : null;
        }

        if (is_int($value) || is_float($value)) {
            return self::fromNumeric((string) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return self::fromNumeric($value);
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return self::inRange($date->getTimestamp()) ? self::fromDate($date) : null;
    }

    /**
     * The current time, formatted for a `DateTime64(9)` column.
     */
    public static function now(): string
    {
        return self::fromDate(Carbon::now());
    }

    /**
     * Format a Carbon instance, padding its microseconds to nanoseconds.
     */
    public static function fromDate(Carbon $date): string
    {
        $date = $date->copy()->utc();

        return self::format($date->getTimestamp(), str_pad((string) $date->micro, 6, '0', STR_PAD_LEFT).'000');
    }

    /**
     * Interpret a numeric timestamp by guessing its unit from its magnitude.
     */
    private static function fromNumeric(string $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        [$whole, $decimals] = array_pad(explode('.', $value, 2), 2, '');

        $whole = ltrim($whole, '+');

        if (preg_match('/^\d+$/', $whole) !== 1) {
            return null;
        }

        $length = strlen(ltrim($whole, '0')) ?: 1;

        $scale = match (true) {
            $length >= 18 => 0,          // nanoseconds
            $length >= 15 => 3,          // microseconds
            $length >= 12 => 6,          // milliseconds
            default => 9,                // seconds
        };

        if ($scale === 0) {
            return self::fromNanos($whole);
        }

        $fraction = substr(str_pad(substr($decimals, 0, $scale), $scale, '0'), 0, $scale);

        return self::fromNanos($whole.$fraction);
    }

    /**
     * Render unix seconds plus a nanosecond fraction as a ClickHouse literal.
     */
    private static function format(int $seconds, string $fraction): string
    {
        return gmdate('Y-m-d H:i:s', $seconds).'.'.substr(str_pad($fraction, self::PRECISION, '0'), 0, self::PRECISION);
    }
}
