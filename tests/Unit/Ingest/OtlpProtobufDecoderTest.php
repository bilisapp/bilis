<?php

declare(strict_types=1);

use App\Services\Ingest\OtlpLogMapper;
use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\Protobuf\OtlpProtobufDecoder;

/**
 * The fixtures are two spellings of the same export: `.bin` as a real Go
 * `otlploghttp` exporter put it on the wire, `.json` as the OTLP/JSON encoding
 * of the very same message. `tests/Fixtures/otlp/main.go` generates both.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function otlpFixture(string $name): array
{
    $directory = dirname(__DIR__, 2).'/Fixtures/otlp/';

    return [
        (string) file_get_contents($directory.$name.'.bin'),
        (array) json_decode((string) file_get_contents($directory.$name.'.json'), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/**
 * Both encodings must map to byte-identical rows. This is the property the
 * decoder exists to have: downstream never learns which one arrived.
 */
it('maps a protobuf export to exactly the rows its JSON twin maps to', function (string $fixture) {
    [$protobuf, $json] = otlpFixture($fixture);

    $mapper = new OtlpLogMapper;

    $fromProtobuf = $mapper->map((new OtlpProtobufDecoder)->decode($protobuf), '7');
    $fromJson = $mapper->map($json, '7');

    expect($fromProtobuf->rejected)->toBe(0)
        ->and($fromJson->rejected)->toBe(0)
        ->and($fromProtobuf->rows)->not->toBeEmpty()
        ->and($fromProtobuf->rows)->toEqual($fromJson->rows);
})->with(['otlp-logs-export', 'otlp-logs-kitchen-sink']);

it('decodes a real exporter body field by field', function () {
    [$protobuf] = otlpFixture('otlp-logs-export');

    $decoded = (new OtlpProtobufDecoder)->decode($protobuf);

    $resourceLogs = $decoded['resourceLogs'][0];
    $records = $resourceLogs['scopeLogs'][0]['logRecords'];

    expect($decoded['resourceLogs'])->toHaveCount(1)
        ->and($resourceLogs['resource']['attributes'])->toContain(
            ['key' => 'service.name', 'value' => ['stringValue' => 'checkout']],
            ['key' => 'deployment.generation', 'value' => ['intValue' => '41']],
            ['key' => 'service.canary', 'value' => ['boolValue' => true]],
        )
        ->and($resourceLogs['scopeLogs'][0]['scope'])->toMatchArray([
            'name' => 'checkout.payments',
            'version' => '1.4.0',
        ])
        ->and($records)->toHaveCount(2);

    // Nanoseconds survive as a string: 2^63 is not far from a 2262 timestamp,
    // and a float would have lost the last digits long before that.
    expect($records[0]['timeUnixNano'])->toBe('1756211400123456789')
        ->and($records[0]['severityNumber'])->toBe(17)
        ->and($records[0]['severityText'])->toBe('ERROR')
        ->and($records[0]['body'])->toBe(['stringValue' => 'Card declined for order 41902'])
        ->and($records[0]['eventName'])->toBe('payment.declined')
        // Trace and span ids are hex, the one exception OTLP/JSON makes to its
        // own base64 rule for bytes fields.
        ->and($records[0]['traceId'])->toBe('5b8efff798038103d269b633813fc60c')
        ->and($records[0]['spanId'])->toBe('eee19b7ec3c1b174')
        ->and($records[0]['flags'])->toBe(1);

    expect($records[0]['attributes'])->toContain(
        ['key' => 'order.id', 'value' => ['stringValue' => '41902']],
        ['key' => 'attempt', 'value' => ['intValue' => '3']],
        ['key' => 'retryable', 'value' => ['boolValue' => true]],
        ['key' => 'amount', 'value' => ['doubleValue' => 19.5]],
        ['key' => 'tags', 'value' => ['arrayValue' => ['values' => [
            ['stringValue' => 'card'],
            ['stringValue' => 'declined'],
        ]]]],
    );
});

it('decodes the value kinds the Go SDK cannot emit', function () {
    [$protobuf] = otlpFixture('otlp-logs-kitchen-sink');

    $decoded = (new OtlpProtobufDecoder)->decode($protobuf);
    $resourceLogs = $decoded['resourceLogs'][0];
    $scopeLogs = $resourceLogs['scopeLogs'][0];
    $attributes = collect($scopeLogs['logRecords'][0]['attributes'])->keyBy('key');

    expect($resourceLogs['schemaUrl'])->toBe('https://opentelemetry.io/schemas/1.30.0')
        ->and($scopeLogs['schemaUrl'])->toBe('https://opentelemetry.io/schemas/1.31.0')
        ->and($scopeLogs['scope']['attributes'])->toBe([
            ['key' => 'scope.owner', 'value' => ['stringValue' => 'payments']],
        ]);

    expect($attributes['issuer']['value'])->toBe(['kvlistValue' => ['values' => [
        ['key' => 'code', 'value' => ['stringValue' => '51']],
        ['key' => 'latency_ms', 'value' => ['intValue' => '240']],
    ]]])
        // Bytes are base64 in OTLP/JSON, so that is what the decoder produces.
        ->and($attributes['digest']['value'])->toBe(['bytesValue' => base64_encode("\xDE\xAD\xBE\xEF")])
        ->and($attributes['matrix']['value']['arrayValue']['values'][0]['arrayValue']['values'])->toBe([
            ['intValue' => '1'],
            ['intValue' => '2'],
        ])
        ->and($attributes['note']['value'])->toBe(['stringValue' => 'faktúra — zaplatená ✅']);
});

it('skips fields it does not know', function () {
    // The kitchen sink fixture carries an unknown field 4095 on the request,
    // which its JSON twin cannot represent — the equivalence test above only
    // passes because it is skipped rather than choked on.
    [$protobuf, $json] = otlpFixture('otlp-logs-kitchen-sink');

    expect((new OtlpProtobufDecoder)->decode($protobuf)['resourceLogs'])
        ->toHaveCount(count($json['resourceLogs']));
});

it('reads an empty body as an export with no records', function () {
    expect((new OtlpProtobufDecoder)->decode(''))->toBe(['resourceLogs' => []]);
});

it('refuses a truncated message', function () {
    [$protobuf] = otlpFixture('otlp-logs-export');

    expect(fn () => (new OtlpProtobufDecoder)->decode(substr($protobuf, 0, 120)))
        ->toThrow(MalformedProtobufException::class);
});

it('refuses JSON sent with the protobuf content type', function () {
    expect(fn () => (new OtlpProtobufDecoder)->decode('{"resourceLogs":[]}'))
        ->toThrow(MalformedProtobufException::class);
});

/**
 * Encode a length-delimited protobuf field: field number, then the bytes.
 */
function pbField(int $field, string $bytes): string
{
    $length = strlen($bytes);
    $prefix = '';

    do {
        $byte = $length & 0x7F;
        $length >>= 7;
        $prefix .= chr($length !== 0 ? $byte | 0x80 : $byte);
    } while ($length !== 0);

    return chr($field << 3 | 2).$prefix.$bytes;
}

/**
 * Wrap a single LogRecord as a whole ExportLogsServiceRequest body.
 */
function pbRequestWithRecord(string $record): string
{
    return pbField(1, pbField(2, pbField(2, $record)));
}

it('scrubs invalid UTF-8 in a string field so the batch still encodes', function () {
    // A body string with a lone continuation byte — valid on the protobuf wire
    // (it is just bytes), but not valid UTF-8, so json_encode would throw.
    $record = pbField(5, pbField(1, "before\xFF\xFEafter"));   // LogRecord{body{stringValue}}

    $decoded = (new OtlpProtobufDecoder)->decode(pbRequestWithRecord($record));
    $body = $decoded['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['body'];

    expect(mb_check_encoding($body['stringValue'], 'UTF-8'))->toBeTrue()
        ->and($body['stringValue'])->toStartWith('before')
        ->and($body['stringValue'])->toEndWith('after')
        ->and(json_encode($decoded, JSON_THROW_ON_ERROR))->toBeString();
});

it('scrubs invalid UTF-8 in an attribute key and value', function () {
    $attribute = pbField(1, "k\xFF").pbField(2, pbField(1, "v\xFF")); // KeyValue{key, value{stringValue}}
    $record = pbField(6, $attribute);                                  // LogRecord{attributes}

    $keyValue = (new OtlpProtobufDecoder)->decode(pbRequestWithRecord($record))['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][0];

    expect(mb_check_encoding($keyValue['key'], 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($keyValue['value']['stringValue'], 'UTF-8'))->toBeTrue();
});

it('leaves valid multi-byte UTF-8 untouched', function () {
    $text = 'faktúra — zaplatená ✅';
    $record = pbField(5, pbField(1, $text));

    $body = (new OtlpProtobufDecoder)->decode(pbRequestWithRecord($record))['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['body'];

    expect($body['stringValue'])->toBe($text);
});

it('decodes deeply nested values in memory proportional to the body', function () {
    // The shape that peaked at ~800 MB before submessages became windows: a
    // fat leaf wrapped to the depth cap. It must now stay near the body size.
    $value = pbField(1, str_repeat('A', 8 * 1024 * 1024)); // AnyValue{stringValue: 8 MB}

    for ($level = 0; $level < OtlpProtobufDecoder::MAX_VALUE_DEPTH; $level++) {
        $value = pbField(6, pbField(1, pbField(1, 'k').pbField(2, $value))); // AnyValue{kvlist{KeyValue{key, value}}}
    }

    $body = pbRequestWithRecord(pbField(5, $value));

    gc_collect_cycles();
    $before = memory_get_usage(true);
    (new OtlpProtobufDecoder)->decode($body);
    $used = memory_get_usage(true) - $before;

    // Windows, not copies: comfortably under 4x the body. The old copying
    // reader was past 50x and would blow the memory limit here.
    expect($used)->toBeLessThan(4 * strlen($body));
});

it('refuses an AnyValue nested deeper than the limit', function () {
    // An arrayValue wrapping an arrayValue wrapping … one level past the cap.
    $value = "\x0A\x00"; // AnyValue{stringValue: ""}

    for ($depth = 0; $depth <= OtlpProtobufDecoder::MAX_VALUE_DEPTH + 1; $depth++) {
        $values = "\x0A".chr(strlen($value)).$value;          // ArrayValue{values: …}
        $value = "\x2A".chr(strlen($values)).$values;         // AnyValue{arrayValue: …}
    }

    $keyValue = "\x0A\x01k\x12".chr(strlen($value)).$value;   // KeyValue{key: "k", value: …}
    $record = "\x32".chr(strlen($keyValue)).$keyValue;        // LogRecord{attributes: …}
    $scopeLogs = "\x12".chr(strlen($record)).$record;         // ScopeLogs{log_records: …}
    $resourceLogs = "\x12".chr(strlen($scopeLogs)).$scopeLogs; // ResourceLogs{scope_logs: …}
    $body = "\x0A".chr(strlen($resourceLogs)).$resourceLogs;   // request{resource_logs: …}

    // Asserted on the message: a length byte gone wrong would also throw, and
    // this test is worth nothing if it passes for that reason instead.
    expect(fn () => (new OtlpProtobufDecoder)->decode($body))
        ->toThrow(MalformedProtobufException::class, 'AnyValue nests deeper than 16 levels.');
});
