<?php

use App\Services\Tools\Traceparent;

it('decodes the example from the specification', function () {
    $parsed = Traceparent::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

    expect($parsed->isValid())->toBeTrue()
        ->and($parsed->version)->toBe('00')
        ->and($parsed->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($parsed->parentId)->toBe('00f067aa0ba902b7')
        ->and($parsed->isSampled())->toBeTrue()
        ->and($parsed->isRandom())->toBeFalse();
});

it('accepts a whole header line, quoted', function () {
    $parsed = Traceparent::parse('traceparent: "00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00"');

    expect($parsed->isValid())->toBeTrue()
        ->and($parsed->isSampled())->toBeFalse();
});

it('parses uppercase hex but says a strict receiver drops it', function () {
    $parsed = Traceparent::parse('00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01');

    expect($parsed->isParsed())->toBeTrue()
        ->and($parsed->isValid())->toBeFalse()
        ->and($parsed->errors[0])->toContain('uppercase');
});

it('rejects what the specification forbids', function (string $header, string $reason) {
    $parsed = Traceparent::parse($header);

    expect($parsed->isValid())->toBeFalse()
        ->and(implode(' ', $parsed->errors))->toContain($reason);
})->with([
    'version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', 'ff is forbidden'],
    'zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01', 'trace id is all zeroes'],
    'zero parent id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01', 'parent id is all zeroes'],
    'version 00 with extra fields' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-ab', 'exactly four fields'],
    'too few fields' => ['00-4bf92f3577b34da6a3ce929d0e0e4736', 'has 2'],
    'short trace id' => ['00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01', 'trace id must be 32'],
    'not hex' => ['00-4bf92f3577b34da6a3ce929d0e0e47zz-00f067aa0ba902b7-01', 'trace id contains'],
]);

it('lets a future version carry extra fields', function () {
    $parsed = Traceparent::parse('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-whatever');

    expect($parsed->isValid())->toBeTrue()
        ->and($parsed->header())->toBe('01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
});

it('generates a valid, sampled, random-flagged header', function () {
    $generated = Traceparent::generate();

    expect($generated->isValid())->toBeTrue()
        ->and($generated->isSampled())->toBeTrue()
        ->and($generated->isRandom())->toBeTrue()
        ->and($generated->header())->toMatch('/^00-[0-9a-f]{32}-[0-9a-f]{16}-03$/')
        ->and(Traceparent::generate()->traceId)->not->toBe($generated->traceId);
});
