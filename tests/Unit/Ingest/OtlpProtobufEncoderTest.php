<?php

use App\Services\Ingest\Protobuf\OtlpProtobufEncoder;
use App\Services\Ingest\Protobuf\ProtobufReader;

beforeEach(function () {
    $this->encoder = new OtlpProtobufEncoder;
});

test('a complete success is the empty message', function () {
    /*
     * Not an oversight and not an optimisation: proto3 omits fields at their
     * default, so an ExportServiceResponse with no partial_success is zero
     * bytes on the wire. This is what the Collector answers with.
     */
    expect($this->encoder->success())->toBe('');
});

test('a partial success round trips through the reader', function () {
    $encoded = $this->encoder->partialSuccess(3, 'Some spans could not be parsed and were skipped.');

    expect(decodeOtlpResponse($encoded))->toBe([
        'rejected' => 3,
        'errorMessage' => 'Some spans could not be parsed and were skipped.',
    ]);
});

test('a zero count is omitted, leaving the message alone', function () {
    /*
     * The case an unreadable body produces: the payload is rejected whole, so
     * there is no count, but the client still has to be told why.
     */
    $encoded = $this->encoder->partialSuccess(0, 'Request body could not be read.');

    expect(decodeOtlpResponse($encoded))->toBe(['errorMessage' => 'Request body could not be read.']);
});

test('a partial success with nothing to report degrades to a success', function () {
    expect($this->encoder->partialSuccess(0, ''))->toBe('');
});

test('counts spanning several varint bytes survive', function () {
    /*
     * 300 is the smallest count needing two bytes, and 2 ** 21 the smallest
     * needing four — a batch big enough to reject that many is not exotic.
     */
    foreach ([1, 127, 128, 300, 2 ** 14, 2 ** 21, 2 ** 31] as $count) {
        expect(decodeOtlpResponse($this->encoder->partialSuccess($count, 'x')))
            ->toBe(['rejected' => $count, 'errorMessage' => 'x']);
    }
});

test('the encoded message is structurally what the schema says', function () {
    $encoded = $this->encoder->partialSuccess(1, 'hi');

    $reader = new ProtobufReader($encoded);

    // Outer: field 1, wire type 2 (length delimited submessage).
    expect($reader->readTag())->toBe([1, 2]);

    $partial = $reader->readMessage();

    // Inner: field 1 varint count, then field 2 length-delimited string.
    expect($partial->readTag())->toBe([1, 0])
        ->and($partial->readVarint())->toBe(1)
        ->and($partial->readTag())->toBe([2, 2])
        ->and($partial->readLengthDelimited())->toBe('hi')
        ->and($partial->atEnd())->toBeTrue()
        ->and($reader->atEnd())->toBeTrue();
});

test('a multibyte error message keeps its bytes', function () {
    $message = 'Ne bolo možné prečítať telo požiadavky — 3 spany preskočené.';

    expect(decodeOtlpResponse($this->encoder->partialSuccess(3, $message)))
        ->toBe(['rejected' => 3, 'errorMessage' => $message]);
});
