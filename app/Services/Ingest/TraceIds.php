<?php

namespace App\Services\Ingest;

/**
 * Normalises trace and span ids onto the lowercase hex the tables store.
 *
 * A log row and a span row are joined by `TraceId = ...` / `SpanId = ...`, so
 * every mapper — OTLP logs, OTLP traces, the simple endpoint and the envelope
 * path — has to write the same spelling of the same id or the link between a
 * log line and its span silently finds nothing. This is the one place that
 * spelling is decided.
 *
 * Two wire encodings are understood. OTLP/JSON specifies hex, and that is what
 * the Collector and every SDK send; but stock `protojson` renders a `bytes`
 * field as base64 (24 characters for a 16-byte trace id, 12 for an 8-byte
 * span id), and a sender that serialised the proto that way has still told us
 * the id. The two lengths never collide, so both are accepted. An all-zero id
 * is the proto's spelling of "absent" and becomes `''`.
 */
final class TraceIds
{
    /**
     * The byte width of a trace id and of a span id.
     */
    public const TRACE_ID_BYTES = 16;

    public const SPAN_ID_BYTES = 8;

    /**
     * The id as lowercase hex, or `''` when it is absent, all zeroes or unusable.
     *
     * The strict form: a span whose ids do not resolve here is not storable
     * (`trace_summary_mv` drops `TraceId = ''`), and the trace mapper counts it
     * as rejected rather than writing a span nothing can ever reach.
     */
    public static function hex(mixed $value, int $bytes): string
    {
        return self::decode($value, $bytes) ?? '';
    }

    /**
     * The id as lowercase hex when it can be read as one, the raw value otherwise.
     *
     * The lenient form, for log rows: a log line is worth keeping even when
     * the id on it is not one we can normalise, so an unrecognised value is
     * stored verbatim rather than lost. It will not link to a span — nothing
     * could make it — but it is still there for the reader to see.
     */
    public static function lenient(mixed $value, int $bytes): string
    {
        $normalised = self::decode($value, $bytes);

        if ($normalised !== null) {
            return $normalised;
        }

        return is_string($value) ? trim($value) : ($value === null ? '' : OtlpValues::stringify($value));
    }

    /**
     * Read an id as hex or base64, or null when it is neither.
     */
    private static function decode(mixed $value, int $bytes): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $hexLength = $bytes * 2;

        if (strlen($value) === $hexLength && ctype_xdigit($value)) {
            return self::nonZero(strtolower($value));
        }

        // A W3C-looking id with dashes in it (some SDKs format trace ids as UUIDs).
        $undashed = str_replace('-', '', $value);

        if ($undashed !== $value && strlen($undashed) === $hexLength && ctype_xdigit($undashed)) {
            return self::nonZero(strtolower($undashed));
        }

        if (strlen($value) === self::base64Length($bytes)) {
            $decoded = base64_decode($value, true);

            if ($decoded !== false && strlen($decoded) === $bytes) {
                return self::nonZero(bin2hex($decoded));
            }
        }

        return null;
    }

    /**
     * The padded base64 length of a byte string, which is what protojson emits.
     */
    private static function base64Length(int $bytes): int
    {
        return (int)(ceil($bytes / 3) * 4);
    }

    /**
     * Empty for the all-zero id, which the proto uses to mean "none".
     */
    private static function nonZero(string $hex): string
    {
        return trim($hex, '0') === '' ? '' : $hex;
    }
}
