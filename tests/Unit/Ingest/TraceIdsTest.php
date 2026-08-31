<?php

use App\Services\Ingest\TraceIds;

/*
 * The one spelling of a trace id every mapper has to agree on. A log row and a
 * span row are joined by `TraceId = ...`, so a log that stored `5B8E…` while the
 * span stored `5b8e…` would link to nothing, silently.
 */
it('normalises a hex id to lowercase', function () {
    expect(TraceIds::hex('5B8EFFF798038103D269B633813FC60C', TraceIds::TRACE_ID_BYTES))->toBe('5b8efff798038103d269b633813fc60c')
        ->and(TraceIds::hex(' eee19b7ec3c1b174 ', TraceIds::SPAN_ID_BYTES))->toBe('eee19b7ec3c1b174')
        ->and(TraceIds::hex('5b8efff7-9803-8103-d269-b633813fc60c', TraceIds::TRACE_ID_BYTES))->toBe('5b8efff798038103d269b633813fc60c');
});

it('decodes the base64 a stock protojson sender writes for a bytes field', function () {
    expect(TraceIds::hex(base64_encode(hex2bin('5b8efff798038103d269b633813fc60c')), TraceIds::TRACE_ID_BYTES))->toBe('5b8efff798038103d269b633813fc60c')
        ->and(TraceIds::hex(base64_encode(hex2bin('eee19b7ec3c1b174')), TraceIds::SPAN_ID_BYTES))->toBe('eee19b7ec3c1b174')
        // Right length, wrong content: 24 base64 chars that decode to 18 bytes.
        ->and(TraceIds::hex('abcdef0123456789abcdef01', TraceIds::TRACE_ID_BYTES))->toBe('')
        // Not base64 at all.
        ->and(TraceIds::hex('!!!!!!!!!!!!!!!!!!!!!!!!', TraceIds::TRACE_ID_BYTES))->toBe('');
});

it('treats an unusable id as absent in the strict form', function (mixed $value) {
    expect(TraceIds::hex($value, TraceIds::TRACE_ID_BYTES))->toBe('')
        ->and(TraceIds::hex($value, TraceIds::SPAN_ID_BYTES))->toBe('');
})->with([
    'null' => [null],
    'empty' => [''],
    'short' => ['abc'],
    'not hex' => [str_repeat('z', 32)],
    'all zeroes, trace width' => [str_repeat('0', 32)],
    'all zeroes, span width' => [str_repeat('0', 16)],
    'an integer' => [12345],
]);

it('keeps the raw value in the lenient form rather than losing it', function () {
    expect(TraceIds::lenient('5B8EFFF798038103D269B633813FC60C', TraceIds::TRACE_ID_BYTES))->toBe('5b8efff798038103d269b633813fc60c')
        ->and(TraceIds::lenient(base64_encode(hex2bin('eee19b7ec3c1b174')), TraceIds::SPAN_ID_BYTES))->toBe('eee19b7ec3c1b174')
        ->and(TraceIds::lenient(' req-42 ', TraceIds::TRACE_ID_BYTES))->toBe('req-42')
        ->and(TraceIds::lenient(str_repeat('z', 32), TraceIds::TRACE_ID_BYTES))->toBe(str_repeat('z', 32))
        ->and(TraceIds::lenient(12345, TraceIds::SPAN_ID_BYTES))->toBe('12345')
        ->and(TraceIds::lenient(null, TraceIds::SPAN_ID_BYTES))->toBe('')
        // All zeroes means "none" in either form; nothing is lost by dropping it.
        ->and(TraceIds::lenient(str_repeat('0', 32), TraceIds::TRACE_ID_BYTES))->toBe('');
});
