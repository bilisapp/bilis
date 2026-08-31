<?php

namespace App\Services\Ingest\Protobuf;

/**
 * Writes the one protobuf message Bilis ever has to produce: the OTLP export
 * *response*.
 *
 * OTLP/HTTP requires the response to carry the same encoding as the request, so
 * a protobuf export must be answered in protobuf. A client that gets JSON back
 * tries to parse it as a protobuf message and reports a wire-format error on
 * every single export, even though the spans were stored — the export looks
 * broken to the operator and fine to us.
 *
 * The counterpart of {@see OtlpProtobufDecoder} and just as hand-rolled: no
 * composer package and no `ext-protobuf`. It stays this small because the
 * response schema is two fields wide and identical for both signals:
 *
 *     ExportTraceServiceResponse { partial_success = 1 }
 *     ExportTracePartialSuccess  { rejected_spans = 1 (int64), error_message = 2 (string) }
 *
 * The logs response is the same shape with `rejected_log_records` in field 1,
 * so one encoder serves both — the field *numbers* are what go on the wire, and
 * only the JSON encoding has to know the two names apart.
 */
class OtlpProtobufEncoder
{
    /**
     * Encode a complete success.
     *
     * Proto3 omits fields holding their default value, and a response whose
     * `partial_success` is unset is exactly that: zero bytes. This is not a
     * shortcut — it is the wire form, and it is what the Collector sends.
     */
    public function success(): string
    {
        return '';
    }

    /**
     * Encode a partial success carrying a rejected count and a reason.
     */
    public function partialSuccess(int $rejected, string $errorMessage): string
    {
        $inner = '';

        /*
         * Proto3 default-value omission again: a zero count is not written. It
         * still reaches this method — an unreadable body rejects the whole
         * payload without being able to count the records inside it — and the
         * message field alone is what tells the client anything happened.
         */
        if ($rejected !== 0) {
            $inner .= $this->tag(1, self::WIRE_VARINT).$this->varint($rejected);
        }

        if ($errorMessage !== '') {
            $inner .= $this->tag(2, self::WIRE_LENGTH_DELIMITED).$this->lengthDelimited($errorMessage);
        }

        if ($inner === '') {
            return $this->success();
        }

        return $this->tag(1, self::WIRE_LENGTH_DELIMITED).$this->lengthDelimited($inner);
    }

    /**
     * Wire type for varint-encoded scalars.
     */
    private const WIRE_VARINT = 0;

    /**
     * Wire type for length-prefixed bytes: strings and submessages.
     */
    private const WIRE_LENGTH_DELIMITED = 2;

    /**
     * Encode a field header, which is the field number and wire type packed
     * into one varint.
     */
    private function tag(int $field, int $wireType): string
    {
        return $this->varint($field << 3 | $wireType);
    }

    /**
     * Encode a base-128 varint, seven bits per byte, little end first, with the
     * high bit set on every byte but the last.
     */
    private function varint(int $value): string
    {
        $out = '';

        while ($value > 0x7F) {
            $out .= chr($value & 0x7F | 0x80);
            $value >>= 7;
        }

        return $out.chr($value);
    }

    /**
     * Prefix a payload with its own length.
     */
    private function lengthDelimited(string $payload): string
    {
        return $this->varint(strlen($payload)).$payload;
    }
}
