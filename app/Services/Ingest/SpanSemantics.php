<?php

namespace App\Services\Ingest;

/**
 * Normalises a span's kind and status onto the literals the table stores.
 *
 * The stored strings are the OpenTelemetry Collector exporter's, not the
 * proto's: `exporter_traces.go` writes `span.Kind().String()` and
 * `spanStatus.Code().String()`, and pdata's `String()` returns `Server` and
 * `Error`, never `SPAN_KIND_SERVER` or `STATUS_CODE_ERROR`. Bilis has to write
 * the same forms so its rows are indistinguishable from a stock exporter's
 * (SCHEMA.md R1, R10) — and because `trace_summary_mv` counts errors with
 * `countIf(StatusCode = 'Error')`, which would silently count zero forever
 * against any other spelling.
 *
 * The wire is less tidy than the table. Protobuf carries these as enum
 * integers; OTLP/JSON carries either the integer or the proto enum name,
 * depending on how the sender's protojson is configured. Both are accepted
 * here, and anything unrecognised falls back to the proto's own zero value,
 * which is what an absent field means.
 *
 * @see https://github.com/open-telemetry/opentelemetry-collector/blob/main/pdata/ptrace/span_kind.go
 * @see https://github.com/open-telemetry/opentelemetry-collector/blob/main/pdata/ptrace/status_code.go
 */
final class SpanSemantics
{
    /**
     * SpanKind, by proto enum number.
     *
     * @var array<int, string>
     */
    private const KINDS = [
        0 => 'Unspecified',
        1 => 'Internal',
        2 => 'Server',
        3 => 'Client',
        4 => 'Producer',
        5 => 'Consumer',
    ];

    /**
     * StatusCode, by proto enum number.
     *
     * @var array<int, string>
     */
    private const STATUS_CODES = [
        0 => 'Unset',
        1 => 'Ok',
        2 => 'Error',
    ];

    /**
     * The proto enum names a JSON sender may use instead of the numbers.
     *
     * @var array<string, int>
     */
    private const KIND_NAMES = [
        'SPAN_KIND_UNSPECIFIED' => 0,
        'SPAN_KIND_INTERNAL' => 1,
        'SPAN_KIND_SERVER' => 2,
        'SPAN_KIND_CLIENT' => 3,
        'SPAN_KIND_PRODUCER' => 4,
        'SPAN_KIND_CONSUMER' => 5,
    ];

    /**
     * @var array<string, int>
     */
    private const STATUS_CODE_NAMES = [
        'STATUS_CODE_UNSET' => 0,
        'STATUS_CODE_OK' => 1,
        'STATUS_CODE_ERROR' => 2,
    ];

    /**
     * The literal stored in `SpanKind` for a wire value.
     */
    public static function kind(mixed $value): string
    {
        return self::KINDS[self::enum($value, self::KIND_NAMES, self::KINDS)] ?? 'Unspecified';
    }

    /**
     * The literal stored in `StatusCode` for a wire value.
     */
    public static function statusCode(mixed $value): string
    {
        return self::STATUS_CODES[self::enum($value, self::STATUS_CODE_NAMES, self::STATUS_CODES)] ?? 'Unset';
    }

    /**
     * Resolve a wire enum to its number, accepting the number or the name.
     *
     * A sender that already speaks the exporter's own `String()` form (`Server`,
     * `Error`) is understood too: it costs one array lookup and means a payload
     * replayed out of our own storage maps back to itself.
     *
     * @param  array<string, int>  $names
     * @param  array<int, string>  $literals
     */
    private static function enum(mixed $value, array $names, array $literals): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $upper = strtoupper($value);

        if (array_key_exists($upper, $names)) {
            return $names[$upper];
        }

        foreach ($literals as $number => $literal) {
            if (strcasecmp($literal, $value) === 0) {
                return $number;
            }
        }

        return 0;
    }
}
