<?php

use App\Services\Tools\TimestampConversion;

it('detects the unit from the number of digits', function (string $input, string $unit) {
    $conversion = TimestampConversion::from($input);

    expect($conversion->detectedUnit)->toBe($unit)
        ->and($conversion->iso())->toStartWith('2023-11-14T22:13:20');
})->with([
    'seconds' => ['1700000000', 's'],
    'milliseconds' => ['1700000000000', 'ms'],
    'microseconds' => ['1700000000000000', 'us'],
    'nanoseconds' => ['1700000000000000000', 'ns'],
]);

it('keeps every nanosecond digit', function () {
    $conversion = TimestampConversion::from('1760020200123456789');

    expect($conversion->nanosecondsString())->toBe('1760020200123456789')
        ->and($conversion->iso())->toBe('2025-10-09T14:30:00.123456789Z')
        ->and($conversion->clickHouse())->toBe('2025-10-09 14:30:00.123456789')
        ->and($conversion->milliseconds())->toBe('1760020200123')
        ->and($conversion->seconds())->toBe('1760020200');
});

it('reads fractional seconds without a float', function () {
    expect(TimestampConversion::from('1700000000.123456789')->nanosecondsString())->toBe('1700000000123456789')
        ->and(TimestampConversion::from('1700000000.5')->nanosecondsString())->toBe('1700000000500000000');
});

it('honours an explicit unit over the guess', function () {
    expect(TimestampConversion::from('1700000000', 'ms')->iso())->toBe('1970-01-20T16:13:20.000000000Z');
});

it('reads dates, keeping nanoseconds a DateTime cannot hold', function () {
    expect(TimestampConversion::from('2025-10-09T14:30:00.123456789Z')->nanosecondsString())->toBe('1760020200123456789')
        ->and(TimestampConversion::from('2025-10-09 16:30:00', 'auto', 'Europe/Bratislava')->seconds())->toBe('1760020200');
});

it('writes the instant in the chosen zone', function () {
    expect(TimestampConversion::from('1760020200', 'auto', 'Europe/Bratislava')->zoned())->toBe('2025-10-09T16:30:00.000000000+02:00');
});

it('falls back to UTC for an unknown zone', function () {
    expect(TimestampConversion::from('0', 'auto', 'Mars/Olympus')->timezone->getName())->toBe('UTC');
});

it('explains what it cannot convert', function (string $input, string $reason) {
    $conversion = TimestampConversion::from($input);

    expect($conversion->isValid())->toBeFalse()
        ->and($conversion->error)->toContain($reason);
})->with([
    'past int64 nanoseconds' => ['99999999999999999999', '2262-04-11'],
    'seconds past 2262' => ['99999999999', '2262-04-11'],
    'negative' => ['-1', 'before 1970'],
    'before the epoch' => ['1960-01-01', 'before 1970'],
    'gibberish' => ['not a time at all', 'neither a number nor a date'],
]);

it('uses now for an empty input', function () {
    $conversion = TimestampConversion::from('', nowNanoseconds: 1_700_000_000_000_000_000);

    expect($conversion->detectedUnit)->toBe('now')
        ->and($conversion->seconds())->toBe('1700000000');
});
