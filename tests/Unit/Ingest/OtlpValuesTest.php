<?php

use App\Services\Ingest\OtlpValues;

test('a bool value keeps its truth, quoted or not', function (mixed $raw, bool $expected) {
    expect(OtlpValues::anyValue(['boolValue' => $raw]))->toBe($expected);
})->with([
    'json true' => [true, true],
    'json false' => [false, false],
    'quoted true' => ['true', true],
    'quoted false' => ['false', false],
]);
