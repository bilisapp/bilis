<?php

use App\Services\Ingest\OtlpTraceMapper;
use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\Protobuf\OtlpProtobufDecoder;

/**
 * The two encodings of one export, as captured by the Go generator.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function traceFixture(string $name): array
{
    $directory = __DIR__.'/../../Fixtures/otlp/';

    return [
        (string) file_get_contents($directory.$name.'.bin'),
        (array) json_decode((string) file_get_contents($directory.$name.'.json'), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/*
 * The property the decoder exists to have: protobuf in and JSON in produce
 * byte-identical rows, so nothing downstream ever learns which arrived. It is
 * only worth asserting because neither fixture was produced by the code under
 * test — the .bin is what a real otlptracehttp exporter put on the wire.
 */
it('maps a protobuf export to the same rows as its JSON encoding', function (string $fixture) {
    [$protobuf, $json] = traceFixture($fixture);

    $mapper = new OtlpTraceMapper;

    $fromProtobuf = $mapper->map((new OtlpProtobufDecoder)->decodeTraces($protobuf), '7');
    $fromJson = $mapper->map($json, '7');

    expect($fromProtobuf->rows)->toBe($fromJson->rows)
        ->and($fromProtobuf->rows)->not->toBeEmpty()
        ->and($fromProtobuf->rejected)->toBe(0);
})->with(['otlp-traces-export', 'otlp-traces-kitchen-sink']);

it('decodes a real exporter body field by field', function () {
    [$protobuf] = traceFixture('otlp-traces-export');

    $decoded = (new OtlpProtobufDecoder)->decodeTraces($protobuf);
    $spans = $decoded['resourceSpans'][0]['scopeSpans'][0]['spans'];

    expect($decoded['resourceSpans'])->toHaveCount(1)
        ->and($spans)->toHaveCount(2)
        ->and($decoded['resourceSpans'][0]['scopeSpans'][0]['scope'])
        ->toMatchArray(['name' => 'checkout.payments', 'version' => '1.4.0']);

    $names = array_column($spans, 'name');

    expect($names)->toContain('POST /checkout')
        ->and($names)->toContain('charge card');
});

it('reads every span shape the SDK cannot emit', function () {
    [$protobuf] = traceFixture('otlp-traces-kitchen-sink');

    $rows = (new OtlpTraceMapper)->map((new OtlpProtobufDecoder)->decodeTraces($protobuf), '7')->rows;

    expect($rows)->toHaveCount(5);

    $byName = array_column($rows, null, 'SpanName');

    // Every kind and status survives the round trip as the exporter's literal.
    expect(array_column($rows, 'SpanKind'))
        ->toBe(['Server', 'Client', 'Producer', 'Consumer', 'Internal'])
        ->and(array_column($rows, 'StatusCode'))
        ->toBe(['Error', 'Ok', 'Unset', 'Unset', 'Unset']);

    // An all-zero parent span id means "no parent" and must normalise to '',
    // which is what trace_summary_mv keys root detection on.
    expect($byName['POST /checkout']['ParentSpanId'])->toBe('')
        ->and($byName['POST /checkout']['TraceState'])->toBe('vendor=1,other=2')
        ->and($byName['POST /checkout']['StatusMessage'])->toBe('checkout failed');

    // A parent outside the batch is kept verbatim — it is a real id, and the
    // tree builder is what decides to render the span at root level.
    expect($byName['emit receipt']['ParentSpanId'])->toBe('deadbeefdeadbeef');

    // Clock skew clamps rather than wrapping into a UInt64.
    expect($byName['consume receipt']['Duration'])->toBe(0);
});

it('keeps a span\'s events and links position aligned through the decoder', function () {
    [$protobuf] = traceFixture('otlp-traces-kitchen-sink');

    $rows = (new OtlpTraceMapper)->map((new OtlpProtobufDecoder)->decodeTraces($protobuf), '7')->rows;
    $root = array_column($rows, null, 'SpanName')['POST /checkout'];

    expect($root['Events.Name'])->toBe(['exception', 'retrying'])
        ->and($root['Events.Attributes'])->toBe([['exception.type' => 'RuntimeException'], []])
        ->and($root['Events.Timestamp'])->toHaveCount(2)
        ->and($root['Links.TraceId'])->toHaveCount(1)
        ->and($root['Links.TraceState'])->toBe(['linked=1'])
        ->and($root['Links.Attributes'])->toBe([['link.kind' => 'follows']]);
});

it('decodes an empty body to an empty export', function () {
    expect((new OtlpProtobufDecoder)->decodeTraces(''))->toBe(['resourceSpans' => []]);
});

it('rejects a truncated trace export rather than guessing', function () {
    [$protobuf] = traceFixture('otlp-traces-export');

    expect(fn () => (new OtlpProtobufDecoder)->decodeTraces(substr($protobuf, 0, 120)))
        ->toThrow(MalformedProtobufException::class);
});
