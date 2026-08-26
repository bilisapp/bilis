<?php

declare(strict_types=1);

use App\Services\Ingest\Protobuf\MalformedProtobufException;
use App\Services\Ingest\Protobuf\ProtobufReader;

/**
 * Encode a field tag the way the wire format does: field number, then wire
 * type, in the low three bits.
 */
function tag(int $field, int $wireType): string
{
    return varint($field << 3 | $wireType);
}

/**
 * Encode a non-negative base 128 varint.
 */
function varint(int $value): string
{
    $bytes = '';

    do {
        $byte = $value & 0x7F;
        $value >>= 7;
        $bytes .= chr($value !== 0 ? $byte | 0x80 : $byte);
    } while ($value !== 0);

    return $bytes;
}

it('reads a tag as a field number and a wire type', function () {
    $reader = new ProtobufReader(tag(9, ProtobufReader::WIRE_LENGTH_DELIMITED));

    expect($reader->readTag())->toBe([9, ProtobufReader::WIRE_LENGTH_DELIMITED])
        ->and($reader->atEnd())->toBeTrue();
});

it('reads varints across the byte boundaries', function (int $value) {
    expect((new ProtobufReader(varint($value)))->readVarint())->toBe($value);
})->with([0, 1, 127, 128, 300, 16383, 16384, 2147483647]);

it('reads a fixed64 above PHP_INT_MAX as an unsigned decimal string', function () {
    // 2^64 - 1, the value a signed read would report as -1.
    $reader = new ProtobufReader(str_repeat("\xFF", 8));

    expect($reader->readFixed64())->toBe('18446744073709551615');
});

it('reads a nanosecond timestamp back exactly', function () {
    $reader = new ProtobufReader(pack('P', 1756211400123456789));

    expect($reader->readFixed64())->toBe('1756211400123456789');
});

it('reads a double little endian', function () {
    expect((new ProtobufReader(pack('e', 19.5)))->readDouble())->toBe(19.5);
});

it('reads a length-delimited chunk', function () {
    $reader = new ProtobufReader(varint(5).'hello');

    expect($reader->readLengthDelimited())->toBe('hello');
});

it('confines a submessage reader to its own window', function () {
    // Parent field 1 is a submessage carrying its own field 1 = "abc"; parent
    // field 2 is varint 9. The child must see only its submessage and stop,
    // leaving field 2 for the parent to read.
    $inner = tag(1, ProtobufReader::WIRE_LENGTH_DELIMITED).varint(3).'abc';
    $bytes = tag(1, ProtobufReader::WIRE_LENGTH_DELIMITED).varint(strlen($inner)).$inner
        .tag(2, ProtobufReader::WIRE_VARINT).varint(9);
    $reader = new ProtobufReader($bytes);

    expect($reader->readTag())->toBe([1, ProtobufReader::WIRE_LENGTH_DELIMITED]);

    $child = $reader->readMessage();
    expect($child->readTag())->toBe([1, ProtobufReader::WIRE_LENGTH_DELIMITED])
        ->and($child->readLengthDelimited())->toBe('abc')
        ->and($child->atEnd())->toBeTrue();

    // The parent resumes exactly after the child's window.
    expect($reader->readTag())->toBe([2, ProtobufReader::WIRE_VARINT])
        ->and($reader->readVarint())->toBe(9)
        ->and($reader->atEnd())->toBeTrue();
});

it('refuses a submessage whose length runs past the parent window', function () {
    $reader = new ProtobufReader(varint(10).'abc');

    expect(fn () => $reader->readMessage())->toThrow(MalformedProtobufException::class);
});

it('honours the start and end window bounds it is constructed with', function () {
    $buffer = 'XXX'.varint(2).'hiYYY';
    $reader = new ProtobufReader($buffer, 3, 6); // just the varint(2)."hi"

    expect($reader->readLengthDelimited())->toBe('hi')
        ->and($reader->atEnd())->toBeTrue();
});

it('skips a field of every supported wire type', function (int $wireType, string $payload) {
    $reader = new ProtobufReader($payload.tag(2, ProtobufReader::WIRE_VARINT).varint(7));

    $reader->skip($wireType);

    expect($reader->readTag())->toBe([2, ProtobufReader::WIRE_VARINT])
        ->and($reader->readVarint())->toBe(7);
})->with([
    'varint' => [ProtobufReader::WIRE_VARINT, "\xAC\x02"],
    'fixed64' => [ProtobufReader::WIRE_FIXED64, '12345678'],
    'length-delimited' => [ProtobufReader::WIRE_LENGTH_DELIMITED, "\x03abc"],
    'fixed32' => [ProtobufReader::WIRE_FIXED32, '1234'],
]);

it('refuses the deprecated group wire types', function (int $wireType) {
    expect(fn () => (new ProtobufReader(''))->skip($wireType))
        ->toThrow(MalformedProtobufException::class);
})->with([ProtobufReader::WIRE_START_GROUP, ProtobufReader::WIRE_END_GROUP, 6, 7]);

it('refuses a field number of zero', function () {
    // Tag byte 0x00: field number 0, which the wire format does not allow.
    expect(fn () => (new ProtobufReader("\x00"))->readTag())
        ->toThrow(MalformedProtobufException::class);
});

it('refuses a read that runs past the end of the message', function () {
    // A length prefix promising ten bytes, with three delivered.
    $reader = new ProtobufReader(varint(10).'abc');

    expect(fn () => $reader->readLengthDelimited())->toThrow(MalformedProtobufException::class);
});

it('refuses a truncated varint', function () {
    // Every byte has its continuation bit set and then the buffer ends.
    expect(fn () => (new ProtobufReader("\x80\x80\x80"))->readVarint())
        ->toThrow(MalformedProtobufException::class);
});

it('refuses a varint longer than ten bytes', function () {
    expect(fn () => (new ProtobufReader(str_repeat("\x80", 11)."\x01"))->readVarint())
        ->toThrow(MalformedProtobufException::class);
});

it('refuses a truncated fixed64', function () {
    expect(fn () => (new ProtobufReader('1234'))->readFixed64())
        ->toThrow(MalformedProtobufException::class);
});
