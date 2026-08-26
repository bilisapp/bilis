<?php

namespace App\Services\Ingest\Protobuf;

/**
 * A cursor over a protobuf wire-format message.
 *
 * This knows nothing about OpenTelemetry: it reads tags, scalars and
 * length-delimited chunks, checks every read against the end of its window,
 * and refuses anything it cannot represent. The message shapes live in
 * {@see OtlpProtobufDecoder}.
 *
 * **Submessages are windows, not copies.** A reader is a `(start, end)` range
 * over a shared buffer; {@see readMessage()} returns a child reader over a
 * sub-range of that same buffer rather than a `substr()` of it. Without this a
 * body nested N deep is copied N times over — 15 MB of adversarial nesting
 * peaked at ~800 MB — because every wrapping level re-copies the bytes below
 * it. Only leaf scalars ({@see readLengthDelimited()}) are copied out, once
 * each. PHP strings are copy-on-write, so sharing the buffer costs a refcount,
 * not memory.
 *
 * The wire format it implements is the whole of it — five wire types, of which
 * two are the deprecated group encoding and are rejected outright:
 *
 * | Wire type | Meaning              | Read as                     |
 * | --------- | -------------------- | --------------------------- |
 * | 0         | varint               | int                         |
 * | 1         | 64-bit               | unsigned decimal string     |
 * | 2         | length-delimited     | string (bytes/UTF-8/message)|
 * | 3, 4      | start/end group      | rejected                    |
 * | 5         | 32-bit               | int                         |
 *
 * @see https://protobuf.dev/programming-guides/encoding/
 */
class ProtobufReader
{
    public const WIRE_VARINT = 0;

    public const WIRE_FIXED64 = 1;

    public const WIRE_LENGTH_DELIMITED = 2;

    public const WIRE_START_GROUP = 3;

    public const WIRE_END_GROUP = 4;

    public const WIRE_FIXED32 = 5;

    /**
     * The most bytes a varint may occupy: 64 bits in 7-bit groups.
     */
    private const MAX_VARINT_BYTES = 10;

    /**
     * The offset one past the last byte this reader may touch.
     */
    private readonly int $end;

    /**
     * The read position, in bytes from the start of the shared buffer.
     */
    private int $offset;

    /**
     * @param  string  $bytes  The shared buffer; never mutated, so never copied.
     * @param  int  $start  The first byte of this reader's window.
     * @param  int|null  $end  One past the last byte, defaulting to the buffer's end.
     */
    public function __construct(
        private readonly string $bytes,
        int $start = 0,
        ?int $end = null,
    ) {
        $this->offset = $start;
        $this->end = $end ?? strlen($bytes);
    }

    /**
     * Whether this reader's window has been fully consumed.
     */
    public function atEnd(): bool
    {
        return $this->offset >= $this->end;
    }

    /**
     * Read a field tag, returning the field number and its wire type.
     *
     * @return array{0: int, 1: int}
     *
     * @throws MalformedProtobufException
     */
    public function readTag(): array
    {
        $tag = $this->readVarint();
        $fieldNumber = $tag >> 3;
        $wireType = $tag & 0x07;

        if ($fieldNumber < 1) {
            throw new MalformedProtobufException("Field number {$fieldNumber} is not valid at offset {$this->offset}.");
        }

        return [$fieldNumber, $wireType];
    }

    /**
     * Read a base 128 varint.
     *
     * Values above `PHP_INT_MAX` wrap to a negative int, which is the same
     * two's complement bit pattern; no OTLP field this decoder reads as a
     * varint can reach that range, and unknown fields are only skipped.
     *
     * @throws MalformedProtobufException
     */
    public function readVarint(): int
    {
        $value = 0;
        $shift = 0;

        for ($read = 0; $read < self::MAX_VARINT_BYTES; $read++) {
            if ($this->offset >= $this->end) {
                throw new MalformedProtobufException("Varint runs past the end of the message at offset {$this->offset}.");
            }

            $byte = ord($this->bytes[$this->offset++]);
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }

            $shift += 7;
        }

        throw new MalformedProtobufException('Varint longer than '.self::MAX_VARINT_BYTES." bytes at offset {$this->offset}.");
    }

    /**
     * Read a fixed 64-bit value as an unsigned decimal string.
     *
     * OTLP timestamps are `fixed64` nanoseconds. They are kept as strings so
     * that a value above `PHP_INT_MAX` neither wraps nor loses digits to a
     * float — the same reason `LogTimestamp` formats them with string
     * arithmetic.
     *
     * @throws MalformedProtobufException
     */
    public function readFixed64(): string
    {
        $signed = (int) $this->unpackOne('P', 8);

        return $signed < 0 ? sprintf('%u', $signed) : (string) $signed;
    }

    /**
     * Read a 64-bit IEEE 754 double, little endian as the wire format requires.
     *
     * @throws MalformedProtobufException
     */
    public function readDouble(): float
    {
        return (float) $this->unpackOne('e', 8);
    }

    /**
     * Read a fixed 32-bit value.
     *
     * @throws MalformedProtobufException
     */
    public function readFixed32(): int
    {
        return (int) $this->unpackOne('V', 4);
    }

    /**
     * Read a length-delimited chunk into a new string: a scalar or byte array.
     *
     * This copies, so it is for leaf values only — a string, a byte field, a
     * key, a name. Submessages must go through {@see readMessage()} instead, or
     * the copy is paid again at every level of nesting.
     *
     * @throws MalformedProtobufException
     */
    public function readLengthDelimited(): string
    {
        [$start, $length] = $this->takeWindow();

        return substr($this->bytes, $start, $length);
    }

    /**
     * Read a length-delimited chunk as a child reader over the shared buffer.
     *
     * No bytes are copied: the child reads the same string this reader holds,
     * bounded to the chunk. This is the difference between decoding deeply
     * nested telemetry in memory proportional to the body and in memory
     * proportional to the body times its depth.
     *
     * @throws MalformedProtobufException
     */
    public function readMessage(): self
    {
        [$start, $length] = $this->takeWindow();

        return new self($this->bytes, $start, $start + $length);
    }

    /**
     * Step over a field this decoder does not know or does not want.
     *
     * Unknown fields are normal: they are how a newer collector talks to an
     * older receiver, so skipping them is required, not defensive.
     *
     * @throws MalformedProtobufException
     */
    public function skip(int $wireType): void
    {
        match ($wireType) {
            self::WIRE_VARINT => $this->readVarint(),
            self::WIRE_FIXED64 => $this->advance(8),
            self::WIRE_LENGTH_DELIMITED => $this->takeWindow(),
            self::WIRE_FIXED32 => $this->advance(4),
            default => throw new MalformedProtobufException(
                "Wire type {$wireType} is not supported at offset {$this->offset}.",
            ),
        };
    }

    /**
     * Read a length prefix and reserve that many bytes, returning their bounds.
     *
     * @return array{0: int, 1: int} The start offset and the length.
     *
     * @throws MalformedProtobufException
     */
    private function takeWindow(): array
    {
        $length = $this->readVarint();

        if ($length < 0) {
            throw new MalformedProtobufException("Negative length {$length} at offset {$this->offset}.");
        }

        $start = $this->advance($length);

        return [$start, $length];
    }

    /**
     * Move the cursor forward, failing if that runs past the window.
     *
     * @return int The offset the cursor started at.
     *
     * @throws MalformedProtobufException
     */
    private function advance(int $count): int
    {
        if ($count < 0 || $this->offset + $count > $this->end) {
            throw new MalformedProtobufException(
                "Read of {$count} bytes at offset {$this->offset} runs past the end of the message at {$this->end}.",
            );
        }

        $start = $this->offset;
        $this->offset += $count;

        return $start;
    }

    /**
     * Read a fixed-width value with the given `unpack()` format.
     *
     * @throws MalformedProtobufException
     */
    private function unpackOne(string $format, int $count): int|float
    {
        $start = $this->advance($count);
        $unpacked = unpack($format, substr($this->bytes, $start, $count));

        if ($unpacked === false || ! isset($unpacked[1]) || ! is_int($unpacked[1]) && ! is_float($unpacked[1])) {
            throw new MalformedProtobufException("Could not read {$count} bytes as '{$format}' at offset {$start}.");
        }

        return $unpacked[1];
    }
}
