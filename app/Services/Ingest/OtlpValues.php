<?php

namespace App\Services\Ingest;

/**
 * The OTLP value shapes both signal mappers have to read.
 *
 * `AnyValue`, `KeyValue` and the nanosecond timestamps are defined once in the
 * OTLP common proto and mean the same thing on a log record and on a span, so
 * they are unwrapped once here rather than twice. Every method takes `mixed`
 * on purpose: the input is a decoded request body, and any field of it may be
 * absent, null, or of a type the sender had no business using.
 *
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/main/opentelemetry/proto/common/v1/common.proto
 */
final class OtlpValues
{
    /**
     * Flatten a list of OTLP KeyValue pairs into a string map.
     *
     * @return array<string, string>
     */
    public static function attributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $flattened = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $key = $attribute['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $flattened[$key] = self::stringify(self::anyValue($attribute['value'] ?? null));
        }

        return $flattened;
    }

    /**
     * Unwrap an OTLP AnyValue into a plain PHP value.
     */
    public static function anyValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('stringValue', $value)) {
            return $value['stringValue'];
        }

        if (array_key_exists('boolValue', $value)) {
            return (bool) $value['boolValue'];
        }

        if (array_key_exists('intValue', $value)) {
            return is_string($value['intValue']) || is_int($value['intValue']) ? $value['intValue'] : null;
        }

        if (array_key_exists('doubleValue', $value)) {
            return is_numeric($value['doubleValue']) ? (float) $value['doubleValue'] : null;
        }

        if (array_key_exists('bytesValue', $value)) {
            return is_string($value['bytesValue']) ? $value['bytesValue'] : null;
        }

        if (array_key_exists('arrayValue', $value)) {
            $values = is_array($value['arrayValue']) ? ($value['arrayValue']['values'] ?? []) : [];

            return array_map(fn (mixed $item): mixed => self::anyValue($item), is_array($values) ? $values : []);
        }

        if (array_key_exists('kvlistValue', $value)) {
            $values = is_array($value['kvlistValue']) ? ($value['kvlistValue']['values'] ?? []) : [];

            /*
             * An object, not an array, so that it always renders as one: PHP
             * turns the keys "0", "1" into integers, and `json_encode` writes an
             * array with such keys as a JSON list — `{"0":"x"}` would come out
             * as `["x"]`, keys gone. An empty kvlist is `{}` for the same reason.
             */
            return (object)self::attributes($values);
        }

        return $value;
    }

    /**
     * Coerce an unwrapped value into the string ClickHouse expects.
     */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * Read a scalar field as a string.
     */
    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : ($value === null ? '' : self::stringify($value));
    }

    /**
     * Format a nanosecond timestamp field, if it holds a usable value.
     *
     * Usable means everything {@see nanosAsInt} accepts that also lands inside
     * the window `LogTimestamp` keeps: a digit string too long for an integer
     * used to saturate under `(int)` into a year ClickHouse refuses — after the
     * async insert had been acked, so the whole block vanished silently. The
     * proto's zero value ("unset") is null here like any other unusable value;
     * it is the caller that knows what to fall back to.
     */
    public static function nanos(mixed $value): ?string
    {
        $nanos = self::nanosAsInt($value);

        if ($nanos === null || $nanos === 0) {
            return null;
        }

        return LogTimestamp::fromNanos($nanos);
    }

    /**
     * Read a nanosecond field as an integer count, or null if it is unusable.
     *
     * Unix nanoseconds are around 1.8e18 and PHP integers hold up to 9.2e18, so
     * the arithmetic a duration needs is safe for any timestamp this side of the
     * year 2262. A value too large to be one is rejected rather than wrapped.
     */
    public static function nanosAsInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        return $value === (string) (int) $value ? (int) $value : null;
    }
}
