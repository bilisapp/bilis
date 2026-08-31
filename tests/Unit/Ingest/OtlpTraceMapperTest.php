<?php

use App\Services\Ingest\OtlpTraceMapper;
use App\Services\Ingest\SpanSemantics;

/**
 * A minimal export request wrapping one span.
 *
 * The span gets a trace id and a span id unless the test supplies its own:
 * a span without either is rejected (nothing could ever reach it), and most
 * tests here are about some other column.
 *
 * @param  array<string, mixed>  $span
 * @param  array<string, mixed>  $resourceAttributes
 * @return array<string, mixed>
 */
function traceRequest(array $span, array $resourceAttributes = ['service.name' => 'checkout']): array
{
    $span = array_merge(['traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16)], $span);

    $attributes = [];

    foreach ($resourceAttributes as $key => $value) {
        $attributes[] = ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    return [
        'resourceSpans' => [[
            'resource' => ['attributes' => $attributes],
            'scopeSpans' => [[
                'scope' => ['name' => 'checkout.http', 'version' => '1.4.0'],
                'spans' => [$span],
            ]],
        ]],
    ];
}

/**
 * The single row a one-span request maps to.
 *
 * @param  array<string, mixed>  $span
 * @return array<string, mixed>
 */
function mapOneSpan(array $span): array
{
    return (new OtlpTraceMapper)->map(traceRequest($span), '7')->rows[0];
}

it('maps a span onto every column of the table, in schema order', function () {
    $row = mapOneSpan([
        'traceId' => '5B8EFFF798038103D269B633813FC60C',
        'spanId' => 'eee19b7ec3c1b174',
        'parentSpanId' => 'aaaaaaaaaaaaaaaa',
        'traceState' => 'vendor=1',
        'name' => 'GET /checkout',
        'kind' => 2,
        'startTimeUnixNano' => '1756550400000000000',
        'endTimeUnixNano' => '1756550400123456789',
        'attributes' => [['key' => 'http.method', 'value' => ['stringValue' => 'GET']]],
        'status' => ['code' => 2, 'message' => 'upstream refused'],
    ]);

    expect(array_keys($row))->toBe([
        'Timestamp', 'TraceId', 'SpanId', 'ParentSpanId', 'TraceState', 'SpanName', 'SpanKind',
        'ServiceName', 'ResourceAttributes', 'ScopeName', 'ScopeVersion', 'SpanAttributes',
        'Duration', 'StatusCode', 'StatusMessage',
        'Events.Timestamp', 'Events.Name', 'Events.Attributes',
        'Links.TraceId', 'Links.SpanId', 'Links.TraceState', 'Links.Attributes',
        'ProjectId',
    ]);

    expect($row['Timestamp'])->toBe('2025-08-30 10:40:00.000000000')
        // Hex ids are stored lowercase whatever case they arrive in.
        ->and($row['TraceId'])->toBe('5b8efff798038103d269b633813fc60c')
        ->and($row['SpanId'])->toBe('eee19b7ec3c1b174')
        ->and($row['ParentSpanId'])->toBe('aaaaaaaaaaaaaaaa')
        ->and($row['TraceState'])->toBe('vendor=1')
        ->and($row['SpanName'])->toBe('GET /checkout')
        ->and($row['ServiceName'])->toBe('checkout')
        ->and($row['ScopeName'])->toBe('checkout.http')
        ->and($row['ScopeVersion'])->toBe('1.4.0')
        ->and($row['SpanAttributes'])->toBe(['http.method' => 'GET'])
        ->and($row['Duration'])->toBe(123456789)
        ->and($row['StatusMessage'])->toBe('upstream refused')
        ->and($row['ProjectId'])->toBe('7');
});

/*
 * The literals are the exporter's, not the proto's. `trace_summary_mv` counts
 * errors with countIf(StatusCode = 'Error'), so writing STATUS_CODE_ERROR here
 * would report zero errors on every trace, forever, without failing anything.
 */
it('normalises status and kind onto the exporter literals', function (mixed $kind, mixed $code, string $expectedKind, string $expectedStatus) {
    $row = mapOneSpan(['kind' => $kind, 'status' => ['code' => $code]]);

    expect($row['SpanKind'])->toBe($expectedKind)
        ->and($row['StatusCode'])->toBe($expectedStatus);
})->with([
    'proto enum numbers' => [2, 2, 'Server', 'Error'],
    'numbers as strings' => ['3', '1', 'Client', 'Ok'],
    'proto enum names' => ['SPAN_KIND_PRODUCER', 'STATUS_CODE_ERROR', 'Producer', 'Error'],
    'the exporter literals themselves' => ['Consumer', 'Ok', 'Consumer', 'Ok'],
    'absent' => [null, null, 'Unspecified', 'Unset'],
    'unrecognised falls back to the proto zero value' => ['nonsense', 'nonsense', 'Unspecified', 'Unset'],
]);

it('never writes the proto enum names the materialized view would miss', function () {
    expect(SpanSemantics::statusCode('STATUS_CODE_ERROR'))->toBe('Error')
        ->and(SpanSemantics::kind('SPAN_KIND_SERVER'))->toBe('Server');
});

/*
 * trace_summary_mv decides which span is the root with ParentSpanId = ''. A root
 * span whose parent arrives as sixteen zeroes — which is how the proto spells
 * "none" — would otherwise leave the trace with no root name at all.
 */
it('normalises an absent parent span id to the empty string', function (mixed $parent) {
    expect(mapOneSpan(['parentSpanId' => $parent])['ParentSpanId'])->toBe('');
})->with([
    'absent' => [null],
    'all zeroes' => ['0000000000000000'],
    'empty' => [''],
    'too short' => ['abc'],
    'not hex' => ['zzzzzzzzzzzzzzzz'],
    'not a string' => [12345],
]);

it('clamps a duration whose clock ran backwards', function () {
    // UInt64 column: a negative would wrap to ~1.8e19 and make this span the
    // slowest in every percentile it appears in.
    $row = mapOneSpan([
        'startTimeUnixNano' => '1756550400123456789',
        'endTimeUnixNano' => '1756550400000000000',
    ]);

    expect($row['Duration'])->toBe(0);
});

it('keeps events position aligned across the three arrays', function () {
    $row = mapOneSpan([
        'events' => [
            ['timeUnixNano' => '1756550400000000000', 'name' => 'first'],
            ['name' => 'second-without-time', 'attributes' => [['key' => 'a', 'value' => ['stringValue' => 'b']]]],
            'not-an-event',
            ['timeUnixNano' => '1756550400500000000', 'name' => 'third'],
        ],
    ]);

    // The non-array event contributes to none of the three, so the arrays stay
    // the same length and index 2 is 'third' in all of them (R12).
    expect($row['Events.Name'])->toBe(['first', 'second-without-time', 'third'])
        ->and($row['Events.Attributes'])->toBe([[], ['a' => 'b'], []])
        ->and($row['Events.Timestamp'])->toHaveCount(3)
        ->and($row['Events.Timestamp'][2])->toBe('2025-08-30 10:40:00.500000000');
});

it('keeps links position aligned across the four arrays', function () {
    $row = mapOneSpan([
        'links' => [
            ['traceId' => str_repeat('a', 32), 'spanId' => str_repeat('b', 16), 'traceState' => 'x=1'],
            ['traceId' => str_repeat('c', 32), 'spanId' => str_repeat('d', 16)],
        ],
    ]);

    expect($row['Links.TraceId'])->toBe([str_repeat('a', 32), str_repeat('c', 32)])
        ->and($row['Links.SpanId'])->toBe([str_repeat('b', 16), str_repeat('d', 16)])
        ->and($row['Links.TraceState'])->toBe(['x=1', ''])
        ->and($row['Links.Attributes'])->toBe([[], []]);
});

it('takes the project id from the caller and never from the payload', function () {
    $payload = traceRequest(
        ['spanId' => 'eee19b7ec3c1b174', 'ProjectId' => '999'],
        ['service.name' => 'checkout', 'bilis.project.id' => '999'],
    );

    $row = (new OtlpTraceMapper)->map($payload, '7')->rows[0];

    expect($row['ProjectId'])->toBe('7');
});

it('skips the spans it cannot read and keeps the rest', function () {
    $payload = traceRequest(['spanId' => 'eee19b7ec3c1b174']);
    $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][] = 'not-a-span';
    $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][] = ['traceId' => str_repeat('c', 32), 'spanId' => 'aaaaaaaaaaaaaaaa'];

    $mapped = (new OtlpTraceMapper)->map($payload, '7');

    expect($mapped->accepted())->toBe(2)
        ->and($mapped->rejected)->toBe(1)
        ->and($mapped->hasRejections())->toBeTrue();
});

it('reports an unreadable body as a rejected payload rather than throwing', function (mixed $payload) {
    $mapped = (new OtlpTraceMapper)->map($payload, '7');

    expect($mapped->rows)->toBe([])
        ->and($mapped->hasRejections())->toBeTrue()
        ->and($mapped->errorMessage)->toBeString();
})->with([
    'null' => [null],
    'a scalar' => ['nope'],
    'resourceSpans of the wrong type' => [['resourceSpans' => 'nope']],
]);

it('accepts the snake_case spelling a hand written payload may use', function () {
    $payload = [
        'resource_spans' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'ledger']]]],
            'scope_spans' => [[
                'scope' => ['name' => 'ledger'],
                'spans' => [[
                    'trace_id' => str_repeat('a', 32),
                    'span_id' => str_repeat('b', 16),
                    'start_time_unix_nano' => '1756550400000000000',
                    'end_time_unix_nano' => '1756550401000000000',
                ]],
            ]],
        ]],
    ];

    $row = (new OtlpTraceMapper)->map($payload, '7')->rows[0];

    expect($row['ServiceName'])->toBe('ledger')
        ->and($row['TraceId'])->toBe(str_repeat('a', 32))
        ->and($row['Duration'])->toBe(1_000_000_000);
});

/*
 * A span whose ids do not resolve is rejected, not stored. `trace_summary_mv`
 * keeps `WHERE TraceId != ''`, so such a row would be invisible in the list,
 * unreachable from any log line, and still reported to the exporter as
 * accepted — the worst of both.
 */
it('rejects a span whose trace id or span id is unusable', function (string $field, mixed $value) {
    $mapped = (new OtlpTraceMapper)->map(traceRequest([$field => $value]), '7');

    expect($mapped->rows)->toBe([])
        ->and($mapped->rejected)->toBe(1);
})->with([
    'trace id too short' => ['traceId', 'abc'],
    'trace id all zeroes' => ['traceId', str_repeat('0', 32)],
    'trace id not hex' => ['traceId', str_repeat('z', 32)],
    'trace id absent' => ['traceId', null],
    'trace id base64 of the wrong width' => ['traceId', base64_encode(str_repeat("\x01", 8))],
    'span id too short' => ['spanId', 'abc'],
    'span id all zeroes' => ['spanId', str_repeat('0', 16)],
    'span id not hex' => ['spanId', str_repeat('z', 16)],
    'span id absent' => ['spanId', null],
    'span id not a string' => ['spanId', 12345],
]);

it('accepts uppercase hex and protojson base64 ids, storing lowercase hex', function (string $field, string $value, string $expected) {
    expect(mapOneSpan([$field => $value])[ucfirst($field)])->toBe($expected);
})->with([
    'uppercase trace id' => ['traceId', '5B8EFFF798038103D269B633813FC60C', '5b8efff798038103d269b633813fc60c'],
    'uppercase span id' => ['spanId', 'EEE19B7EC3C1B174', 'eee19b7ec3c1b174'],
    // Stock protojson renders a bytes field as padded base64: 24 chars for 16 bytes.
    'base64 trace id' => ['traceId', base64_encode(hex2bin('5b8efff798038103d269b633813fc60c')), '5b8efff798038103d269b633813fc60c'],
    // …and 12 chars for 8 bytes.
    'base64 span id' => ['spanId', base64_encode(hex2bin('eee19b7ec3c1b174')), 'eee19b7ec3c1b174'],
]);

it('decodes a base64 parent span id and link ids the same way', function () {
    $row = mapOneSpan([
        'parentSpanId' => base64_encode(hex2bin('aaaaaaaaaaaaaaaa')),
        'links' => [['traceId' => base64_encode(hex2bin(str_repeat('c', 32))), 'spanId' => base64_encode(hex2bin(str_repeat('d', 16)))]],
    ]);

    expect($row['ParentSpanId'])->toBe('aaaaaaaaaaaaaaaa')
        ->and($row['Links.TraceId'])->toBe([str_repeat('c', 32)])
        ->and($row['Links.SpanId'])->toBe([str_repeat('d', 16)]);
});

/*
 * A start the table cannot hold is a rejected span, never a span dated now().
 * The over-long string is the one that used to saturate under (int) into the
 * year 292277026596 — acked by ClickHouse, then dropped with the whole block.
 * The small ones are seconds or milliseconds sent as nanoseconds: a row dated
 * 1970 expires at the next TTL merge, so a fake timestamp would only hide it.
 */
it('rejects a span whose start time cannot be stored', function (mixed $start) {
    $mapped = (new OtlpTraceMapper)->map(traceRequest(['startTimeUnixNano' => $start]), '7');

    expect($mapped->rows)->toBe([])
        ->and($mapped->rejected)->toBe(1);
})->with([
    'thirty digits' => [str_repeat('9', 30)],
    'just past the int range' => ['9223372036854775808'],
    'past 2261' => ['9183110400000000000'],
    'seconds mistaken for nanos' => ['1756550400'],
    'milliseconds mistaken for nanos' => ['1756550400000'],
    'before 2000' => ['946684799999999999'],
    'negative' => [-5],
    'not digits' => ['soon'],
]);

it('still dates a span that carries no start at all at ingest time', function () {
    $row = mapOneSpan(['endTimeUnixNano' => '1756550400000000000']);

    expect($row['Timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{9}$/')
        ->and($row['Duration'])->toBe(0);
});

it('dates an event with a missing or unusable timestamp at the span start', function () {
    $row = mapOneSpan([
        'startTimeUnixNano' => '1756550400000000000',
        'events' => [
            ['name' => 'no-time'],
            ['name' => 'zero', 'timeUnixNano' => '0'],
            ['name' => 'overflow', 'timeUnixNano' => str_repeat('9', 30)],
            ['name' => 'seconds', 'timeUnixNano' => '1756550400'],
            ['name' => 'fine', 'timeUnixNano' => '1756550400500000000'],
        ],
    ]);

    expect($row['Events.Timestamp'])->toBe([
        '2025-08-30 10:40:00.000000000',
        '2025-08-30 10:40:00.000000000',
        '2025-08-30 10:40:00.000000000',
        '2025-08-30 10:40:00.000000000',
        '2025-08-30 10:40:00.500000000',
    ]);
});

it('reads an integral float as the enum number it spells', function () {
    $row = mapOneSpan(['kind' => 2.0, 'status' => ['code' => 2.0]]);

    expect($row['SpanKind'])->toBe('Server')
        ->and($row['StatusCode'])->toBe('Error')
        ->and(SpanSemantics::kind(2.5))->toBe('Unspecified')
        ->and(SpanSemantics::statusCode('1.0'))->toBe('Ok');
});

it('keeps a numeric attribute key so the writer can serialise it as a map', function () {
    $row = mapOneSpan([
        'attributes' => [['key' => '0', 'value' => ['stringValue' => 'x']]],
        'events' => [['name' => 'e', 'attributes' => [['key' => '1', 'value' => ['stringValue' => 'y']]]]],
    ]);

    // PHP has already turned the key into an int; that is exactly why the writer
    // casts every map to an object rather than trusting json_encode.
    expect($row['SpanAttributes'])->toBe([0 => 'x'])
        ->and($row['Events.Attributes'])->toBe([[1 => 'y']]);
});
