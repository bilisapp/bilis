<?php

namespace App\Services\Ingest\Protobuf;

/**
 * The OTLP messages every signal shares: Resource, InstrumentationScope,
 * KeyValue and AnyValue, and the UTF-8 scrub every string field goes through.
 *
 * Logs, traces and metrics wrap their records in the same envelope, so each
 * signal's decoder uses these rather than a copy: a fix to AnyValue depth or
 * to the UTF-8 scrub lands once. Everything here follows the conventions the
 * decoders document — one method per message, windows not copies, unknown
 * fields skipped, output shaped as protojson would give it.
 */
trait DecodesOtlpCommon
{
    /**
     * How deeply an `AnyValue` may nest arrays and key-value lists.
     *
     * A hostile body can otherwise describe an arbitrarily deep tree in a few
     * bytes per level and exhaust the stack. Real telemetry is one or two
     * levels deep.
     */
    public const MAX_VALUE_DEPTH = 16;

    /** Resource: `repeated KeyValue attributes = 1`. */
    private const RESOURCE_ATTRIBUTES = 1;

    /** InstrumentationScope: name = 1, version = 2, attributes = 3, dropped_attributes_count = 4. */
    private const SCOPE_NAME = 1;

    private const SCOPE_VERSION = 2;

    private const SCOPE_ATTRIBUTES = 3;

    private const SCOPE_DROPPED_ATTRIBUTES_COUNT = 4;

    /** KeyValue: key = 1, value = 2. */
    private const KEY_VALUE_KEY = 1;

    private const KEY_VALUE_VALUE = 2;

    /** AnyValue, one field per kind — exactly one of them is set. */
    private const ANY_STRING = 1;

    private const ANY_BOOL = 2;

    private const ANY_INT = 3;

    private const ANY_DOUBLE = 4;

    private const ANY_ARRAY = 5;

    private const ANY_KVLIST = 6;

    private const ANY_BYTES = 7;

    /** ArrayValue and KeyValueList both hold `repeated … values = 1`. */
    private const VALUES = 1;

    /**
     * Resource.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function resource(ProtobufReader $reader): array
    {
        $attributes = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::RESOURCE_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $attributes[] = $this->keyValue($reader->readMessage());

                continue;
            }

            $reader->skip($wireType);
        }

        return ['attributes' => $attributes];
    }

    /**
     * InstrumentationScope.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function scope(ProtobufReader $reader): array
    {
        $scope = [];
        $attributes = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::SCOPE_DROPPED_ATTRIBUTES_COUNT && $wireType === ProtobufReader::WIRE_VARINT) {
                $scope['droppedAttributesCount'] = $reader->readVarint();

                continue;
            }

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::SCOPE_NAME => $scope['name'] = $this->utf8($reader->readLengthDelimited()),
                self::SCOPE_VERSION => $scope['version'] = $this->utf8($reader->readLengthDelimited()),
                self::SCOPE_ATTRIBUTES => $attributes[] = $this->keyValue($reader->readMessage()),
                default => $reader->skip($wireType),
            };
        }

        if ($attributes !== []) {
            $scope['attributes'] = $attributes;
        }

        return $scope;
    }

    /**
     * KeyValue.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function keyValue(ProtobufReader $reader, int $depth = 0): array
    {
        $pair = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::KEY_VALUE_KEY => $pair['key'] = $this->utf8($reader->readLengthDelimited()),
                self::KEY_VALUE_VALUE => $pair['value'] = $this->anyValue($reader->readMessage(), $depth),
                default => $reader->skip($wireType),
            };
        }

        return $pair;
    }

    /**
     * AnyValue, in the OTLP/JSON spelling of whichever kind is set.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function anyValue(ProtobufReader $reader, int $depth): array
    {
        if ($depth > self::MAX_VALUE_DEPTH) {
            throw new MalformedProtobufException('AnyValue nests deeper than '.self::MAX_VALUE_DEPTH.' levels.');
        }

        $value = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::ANY_STRING && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $value['stringValue'] = $this->utf8($reader->readLengthDelimited());
            } elseif ($field === self::ANY_BOOL && $wireType === ProtobufReader::WIRE_VARINT) {
                $value['boolValue'] = $reader->readVarint() !== 0;
            } elseif ($field === self::ANY_INT && $wireType === ProtobufReader::WIRE_VARINT) {
                // 64-bit ints are strings in OTLP/JSON, so they survive a JSON parser.
                $value['intValue'] = (string) $reader->readVarint();
            } elseif ($field === self::ANY_DOUBLE && $wireType === ProtobufReader::WIRE_FIXED64) {
                $value['doubleValue'] = $reader->readDouble();
            } elseif ($field === self::ANY_ARRAY && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $value['arrayValue'] = ['values' => $this->arrayValues($reader->readMessage(), $depth + 1)];
            } elseif ($field === self::ANY_KVLIST && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $value['kvlistValue'] = ['values' => $this->kvlistValues($reader->readMessage(), $depth + 1)];
            } elseif ($field === self::ANY_BYTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                // Bytes are base64 in OTLP/JSON; only trace and span ids are hex.
                $value['bytesValue'] = base64_encode($reader->readLengthDelimited());
            } else {
                $reader->skip($wireType);
            }
        }

        return $value;
    }

    /**
     * ArrayValue.values.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MalformedProtobufException
     */
    private function arrayValues(ProtobufReader $reader, int $depth): array
    {
        $values = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::VALUES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $values[] = $this->anyValue($reader->readMessage(), $depth);

                continue;
            }

            $reader->skip($wireType);
        }

        return $values;
    }

    /**
     * KeyValueList.values.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MalformedProtobufException
     */
    private function kvlistValues(ProtobufReader $reader, int $depth): array
    {
        $values = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::VALUES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $values[] = $this->keyValue($reader->readMessage(), $depth);

                continue;
            }

            $reader->skip($wireType);
        }

        return $values;
    }

    /**
     * Return a string that `json_encode` will accept.
     *
     * A protobuf `string` is only *meant* to be UTF-8 (the OTLP spec requires
     * it), but the wire carries raw bytes, so a non-conforming or corrupted
     * exporter can put anything here. `json_encode` throws on an ill-formed
     * sequence — and one such byte in one attribute would otherwise fail the
     * insert of the whole batch and come back as a 503 the exporter retries
     * forever. Scrubbing keeps the record, keeps its batch, and keeps the value
     * searchable, at the cost of the offending bytes becoming a replacement
     * character. The fast path is a valid string, which is every real one.
     */
    private function utf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
