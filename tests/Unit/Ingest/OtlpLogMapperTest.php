<?php

use App\Services\Ingest\OtlpLogMapper;

/**
 * A one-record export request.
 *
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function logRequest(array $record): array
{
    return ['resourceLogs' => [['scopeLogs' => [['logRecords' => [$record]]]]]];
}

/*
 * The same over-long digit string that broke spans breaks log records: (int)
 * saturates it into a year ClickHouse rejects after the ack. The event time
 * falls back to the observed time; only when both were supplied and neither is
 * storable is the record counted as rejected — never a 400.
 */
it('falls back to the observed time when the event time cannot be stored', function (mixed $time) {
    $mapped = (new OtlpLogMapper)->map(logRequest([
        'timeUnixNano' => $time,
        'observedTimeUnixNano' => '1756550400500000000',
        'body' => ['stringValue' => 'hello'],
    ]), '7');

    expect($mapped->rejected)->toBe(0)
        ->and($mapped->rows[0]['Timestamp'])->toBe('2025-08-30 10:40:00.500000000');
})->with([
    'thirty digits' => [str_repeat('9', 30)],
    'seconds mistaken for nanos' => ['1756550400'],
    'zero' => ['0'],
]);

it('rejects a record whose event and observed times are both unusable', function () {
    $mapped = (new OtlpLogMapper)->map(logRequest([
        'timeUnixNano' => str_repeat('9', 30),
        'observedTimeUnixNano' => '1756550400',
        'body' => ['stringValue' => 'hello'],
    ]), '7');

    expect($mapped->rows)->toBe([])
        ->and($mapped->rejected)->toBe(1)
        ->and($mapped->hasRejections())->toBeTrue();
});

it('rejects a record whose only supplied time is unusable', function () {
    $mapped = (new OtlpLogMapper)->map(logRequest([
        'timeUnixNano' => '9223372036854775808',
        'body' => ['stringValue' => 'hello'],
    ]), '7');

    expect($mapped->rows)->toBe([])
        ->and($mapped->rejected)->toBe(1);
});

it('dates a record that names no time at all at ingest', function () {
    $mapped = (new OtlpLogMapper)->map(logRequest(['body' => ['stringValue' => 'hello']]), '7');

    expect($mapped->rejected)->toBe(0)
        ->and($mapped->rows[0]['Timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{9}$/');
});

/*
 * The log side of the log↔trace link. The trace mapper stores lowercase hex,
 * `LogQuery` filters on lowercase hex, so the log row has to store lowercase
 * hex too — but a log line with an id we cannot read is still a log line, so
 * the raw value stays rather than being blanked.
 */
it('normalises trace and span ids like the trace mapper, keeping what it cannot read', function (mixed $traceId, mixed $spanId, string $expectedTrace, string $expectedSpan) {
    $row = (new OtlpLogMapper)->map(logRequest([
        'traceId' => $traceId,
        'spanId' => $spanId,
        'body' => ['stringValue' => 'hello'],
    ]), '7')->rows[0];

    expect($row['TraceId'])->toBe($expectedTrace)
        ->and($row['SpanId'])->toBe($expectedSpan);
})->with([
    'uppercase hex' => ['5B8EFFF798038103D269B633813FC60C', 'EEE19B7EC3C1B174', '5b8efff798038103d269b633813fc60c', 'eee19b7ec3c1b174'],
    'protojson base64' => [base64_encode(hex2bin('5b8efff798038103d269b633813fc60c')), base64_encode(hex2bin('eee19b7ec3c1b174')), '5b8efff798038103d269b633813fc60c', 'eee19b7ec3c1b174'],
    'all zeroes' => [str_repeat('0', 32), str_repeat('0', 16), '', ''],
    'not hex is kept verbatim' => ['request-4821', 'worker-7', 'request-4821', 'worker-7'],
    'absent' => [null, null, '', ''],
]);

it('renders a kvlist body with numeric keys as an object, not a list', function () {
    $row = (new OtlpLogMapper)->map(logRequest([
        'body' => ['kvlistValue' => ['values' => [
            ['key' => '0', 'value' => ['stringValue' => 'x']],
            ['key' => 'k', 'value' => ['kvlistValue' => ['values' => []]]],
        ]]],
        'attributes' => [['key' => 'nested', 'value' => ['kvlistValue' => ['values' => [
            ['key' => '1', 'value' => ['intValue' => '2']],
        ]]]]],
    ]), '7')->rows[0];

    // Nested kvlists are stringified one level down, as they always were; what
    // matters is that "0" survives as a key and an empty map is `{}`.
    expect($row['Body'])->toBe('{"0":"x","k":"{}"}')
        ->and($row['LogAttributes'])->toBe(['nested' => '{"1":"2"}']);
});
