<?php

use App\Services\Ingest\LogTimestamp;
use App\Services\Ingest\OtlpValues;
use Illuminate\Support\Carbon;

it('formats nanoseconds without losing precision', function () {
    expect(LogTimestamp::fromNanos('1756550400123456789'))->toBe('2025-08-30 10:40:00.123456789')
        ->and(LogTimestamp::fromNanos(1756550400000000001))->toBe('2025-08-30 10:40:00.000000001');
});

/*
 * `DateTime64(9)` ends in 2262, and a digit string longer than an int used to
 * saturate under `(int)` into the year 292277026596 — which ClickHouse refuses
 * after the async insert was already acked, taking the whole block with it.
 * Below 2000 is the other unit mistake: seconds or millis sent as nanos land
 * in 1970 and expire at the next TTL merge.
 */
it('refuses nanoseconds outside the window the table keeps', function (int|string $nanos) {
    expect(LogTimestamp::fromNanos($nanos))->toBeNull()
        ->and(OtlpValues::nanos($nanos))->toBeNull();
})->with([
    'thirty digits' => [str_repeat('9', 30)],
    'twenty digits' => ['92233720368547758070'],
    'first second of 2261' => ['9183110400000000000'],
    'last nanosecond of 1999' => ['946684799999999999'],
    'seconds' => ['1756550400'],
    'milliseconds' => [1756550400000],
    'zero' => [0],
]);

it('accepts the edges of the window', function () {
    expect(LogTimestamp::fromNanos('946684800000000000'))->toBe('2000-01-01 00:00:00.000000000')
        ->and(LogTimestamp::fromNanos('9183110399999999999'))->toBe('2260-12-31 23:59:59.999999999')
        ->and(OtlpValues::nanos('946684800000000000'))->toBe('2000-01-01 00:00:00.000000000');
});

it('refuses parsed dates outside the window too', function (mixed $value) {
    expect(LogTimestamp::parse($value))->toBeNull();
})->with([
    'a far future iso date' => ['9999-12-31T23:59:59Z'],
    'a pre-2000 iso date' => ['1999-12-31T23:59:59Z'],
    'an over-long numeric string' => [str_repeat('9', 30)],
    'a pre-2000 unix second' => [5],
    'a date object before 2000' => [fn() => Carbon::parse('1970-01-02', 'UTC')],
]);

it('still guesses units for a numeric timestamp inside the window', function () {
    expect(LogTimestamp::parse('1756550400'))->toBe('2025-08-30 10:40:00.000000000')
        ->and(LogTimestamp::parse(1756550400123))->toBe('2025-08-30 10:40:00.123000000')
        ->and(LogTimestamp::parse('2025-08-30T10:40:00.5Z'))->toBe('2025-08-30 10:40:00.500000000');
});
