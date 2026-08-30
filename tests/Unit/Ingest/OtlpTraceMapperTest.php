<?php

use App\Services\Ingest\OtlpTraceMapper;
use App\Services\Ingest\SpanSemantics;

/**
 * A minimal export request wrapping one span.
 *
 * @param  array<string, mixed>  $span
 * @param  array<string, mixed>  $resourceAttributes
 * @return array<string, mixed>
 */
function traceRequest(array $span, array $resourceAttributes = ['service.name' => 'checkout']): array
{
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
    $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][] = ['spanId' => 'aaaaaaaaaaaaaaaa'];

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
