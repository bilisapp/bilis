<?php

namespace App\Services\Ingest\Protobuf;

/**
 * Decodes an OTLP `ExportLogsServiceRequest` from protobuf into the array
 * shape the OTLP/JSON endpoint already produces.
 *
 * Nothing downstream changes: the output is what `json_decode` would have
 * returned for the same export in JSON encoding, so `OtlpLogMapper` maps it
 * without knowing which encoding arrived. That equivalence is the property the
 * tests assert, against fixtures captured from a real Go `otlploghttp`
 * exporter (`tests/Fixtures/otlp`).
 *
 * Auditing this file: there is one private method per protobuf message, each
 * named after it and taking a `ProtobufReader` over that message's bytes, each
 * a loop over `readTag()` with a `match` on the field number and a `skip()`
 * default. Submessages are read with `readMessage()` (a window over the shared
 * buffer, no copy); only leaf scalars are copied out. The constants carry the
 * field numbers from the `.proto` definitions, which are frozen by the
 * protobuf compatibility rules — a field number never changes meaning.
 *
 * A protobuf `string` is raw bytes on the wire, not guaranteed UTF-8, whereas
 * a JSON string always is. So every field that becomes a JSON string is run
 * through {@see utf8()}: an ill-formed byte sequence is scrubbed rather than
 * carried through to `json_encode`, which would otherwise throw and turn one
 * bad byte into a 503 that fails the entire batch and loops the exporter. Byte
 * fields do not need this — they are base64 or hex, always valid UTF-8.
 *
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/main/opentelemetry/proto/logs/v1/logs.proto
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/main/opentelemetry/proto/common/v1/common.proto
 * @see https://opentelemetry.io/docs/specs/otlp/#json-protobuf-encoding
 */
class OtlpProtobufDecoder
{
    /**
     * How deeply an `AnyValue` may nest arrays and key-value lists.
     *
     * A hostile body can otherwise describe an arbitrarily deep tree in a few
     * bytes per level and exhaust the stack. Real telemetry is one or two
     * levels deep.
     */
    public const MAX_VALUE_DEPTH = 16;

    /** ExportLogsServiceRequest: `repeated ResourceLogs resource_logs = 1`. */
    private const REQUEST_RESOURCE_LOGS = 1;

    /** ResourceLogs: resource = 1, scope_logs = 2, schema_url = 3. */
    private const RESOURCE_LOGS_RESOURCE = 1;

    private const RESOURCE_LOGS_SCOPE_LOGS = 2;

    private const RESOURCE_LOGS_SCHEMA_URL = 3;

    /** Resource: `repeated KeyValue attributes = 1`. */
    private const RESOURCE_ATTRIBUTES = 1;

    /** ScopeLogs: scope = 1, log_records = 2, schema_url = 3. */
    private const SCOPE_LOGS_SCOPE = 1;

    private const SCOPE_LOGS_LOG_RECORDS = 2;

    private const SCOPE_LOGS_SCHEMA_URL = 3;

    /** InstrumentationScope: name = 1, version = 2, attributes = 3. */
    private const SCOPE_NAME = 1;

    private const SCOPE_VERSION = 2;

    private const SCOPE_ATTRIBUTES = 3;

    /** LogRecord, in field number order; 4 was never assigned. */
    private const RECORD_TIME_UNIX_NANO = 1;

    private const RECORD_SEVERITY_NUMBER = 2;

    private const RECORD_SEVERITY_TEXT = 3;

    private const RECORD_BODY = 5;

    private const RECORD_ATTRIBUTES = 6;

    private const RECORD_FLAGS = 8;

    private const RECORD_TRACE_ID = 9;

    private const RECORD_SPAN_ID = 10;

    private const RECORD_OBSERVED_TIME_UNIX_NANO = 11;

    private const RECORD_EVENT_NAME = 12;

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
     * Decode an export request body.
     *
     * @return array{resourceLogs: array<int, array<string, mixed>>}
     *
     * @throws MalformedProtobufException
     */
    public function decode(string $body): array
    {
        return $this->request(new ProtobufReader($body));
    }

    /**
     * ExportLogsServiceRequest.
     *
     * @return array{resourceLogs: array<int, array<string, mixed>>}
     *
     * @throws MalformedProtobufException
     */
    private function request(ProtobufReader $reader): array
    {
        $resourceLogs = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::REQUEST_RESOURCE_LOGS && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $resourceLogs[] = $this->resourceLogs($reader->readMessage());

                continue;
            }

            $reader->skip($wireType);
        }

        return ['resourceLogs' => $resourceLogs];
    }

    /**
     * ResourceLogs.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function resourceLogs(ProtobufReader $reader): array
    {
        $resourceLogs = [];
        $scopeLogs = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::RESOURCE_LOGS_RESOURCE => $resourceLogs['resource'] = $this->resource($reader->readMessage()),
                self::RESOURCE_LOGS_SCOPE_LOGS => $scopeLogs[] = $this->scopeLogs($reader->readMessage()),
                self::RESOURCE_LOGS_SCHEMA_URL => $resourceLogs['schemaUrl'] = $this->utf8($reader->readLengthDelimited()),
                default => $reader->skip($wireType),
            };
        }

        $resourceLogs['scopeLogs'] = $scopeLogs;

        return $resourceLogs;
    }

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
     * ScopeLogs.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function scopeLogs(ProtobufReader $reader): array
    {
        $scopeLogs = [];
        $logRecords = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($wireType !== ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $reader->skip($wireType);

                continue;
            }

            match ($field) {
                self::SCOPE_LOGS_SCOPE => $scopeLogs['scope'] = $this->scope($reader->readMessage()),
                self::SCOPE_LOGS_LOG_RECORDS => $logRecords[] = $this->logRecord($reader->readMessage()),
                self::SCOPE_LOGS_SCHEMA_URL => $scopeLogs['schemaUrl'] = $this->utf8($reader->readLengthDelimited()),
                default => $reader->skip($wireType),
            };
        }

        $scopeLogs['logRecords'] = $logRecords;

        return $scopeLogs;
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
     * LogRecord.
     *
     * Trace and span ids are hex here, not base64: the OTLP/JSON encoding
     * makes those two fields an explicit exception to its own base64 rule, and
     * the mapper stores them as they arrive.
     *
     * @return array<string, mixed>
     *
     * @throws MalformedProtobufException
     */
    private function logRecord(ProtobufReader $reader): array
    {
        $record = [];
        $attributes = [];

        while (! $reader->atEnd()) {
            [$field, $wireType] = $reader->readTag();

            if ($field === self::RECORD_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64) {
                $record['timeUnixNano'] = $reader->readFixed64();
            } elseif ($field === self::RECORD_OBSERVED_TIME_UNIX_NANO && $wireType === ProtobufReader::WIRE_FIXED64) {
                $record['observedTimeUnixNano'] = $reader->readFixed64();
            } elseif ($field === self::RECORD_SEVERITY_NUMBER && $wireType === ProtobufReader::WIRE_VARINT) {
                $record['severityNumber'] = $reader->readVarint();
            } elseif ($field === self::RECORD_SEVERITY_TEXT && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $record['severityText'] = $this->utf8($reader->readLengthDelimited());
            } elseif ($field === self::RECORD_BODY && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $record['body'] = $this->anyValue($reader->readMessage(), 0);
            } elseif ($field === self::RECORD_ATTRIBUTES && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $attributes[] = $this->keyValue($reader->readMessage());
            } elseif ($field === self::RECORD_FLAGS && $wireType === ProtobufReader::WIRE_FIXED32) {
                $record['flags'] = $reader->readFixed32();
            } elseif ($field === self::RECORD_TRACE_ID && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $record['traceId'] = bin2hex($reader->readLengthDelimited());
            } elseif ($field === self::RECORD_SPAN_ID && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $record['spanId'] = bin2hex($reader->readLengthDelimited());
            } elseif ($field === self::RECORD_EVENT_NAME && $wireType === ProtobufReader::WIRE_LENGTH_DELIMITED) {
                $record['eventName'] = $this->utf8($reader->readLengthDelimited());
            } else {
                $reader->skip($wireType);
            }
        }

        if ($attributes !== []) {
            $record['attributes'] = $attributes;
        }

        return $record;
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
