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
     * Format a unix nanosecond timestamp, given as an integer or a string.
     */
    public static function fromNanos(int|string $nanos): ?string
    {
        $digits = ltrim((string) $nanos);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        $digits = str_pad($digits, self::PRECISION + 1, '0', STR_PAD_LEFT);

        $seconds = substr($digits, 0, -self::PRECISION);
        $fraction = substr($digits, -self::PRECISION);

        return self::format((int) $seconds, $fraction);
    }

    /**
     * Format an arbitrary user supplied timestamp value.
     *
     * Accepts ISO 8601 strings, unix seconds, milliseconds, microseconds and
     * nanoseconds. Returns null when the value cannot be understood.
     */
    public static function parse(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return self::fromDate(Carbon::instance($value));
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
            return self::fromDate(Carbon::parse($value));
        } catch (Throwable) {
            return null;
        }
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
