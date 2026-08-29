<?php

use App\Models\Project;

test('an origin is reduced to scheme, host and port', function (string $input, ?string $expected) {
    expect(Project::normalizeOrigin($input))->toBe($expected);
})->with([
    ['https://shop.example.com', 'https://shop.example.com'],
    ['https://shop.example.com/', 'https://shop.example.com'],
    ['https://SHOP.Example.com/checkout?x=1', 'https://shop.example.com'],
    ['http://localhost:5173', 'http://localhost:5173'],
    // No scheme is the common paste; https is the safe reading of it.
    ['shop.example.com', 'https://shop.example.com'],
    ['*', '*'],
    ['', null],
    ['   ', null],
    // The literal a browser sends from a sandboxed iframe or a file:// page.
    ['null', null],
    ['ftp://files.example.com', null],
]);

test('a list keeps its order, drops duplicates and drops what cannot be read', function () {
    $origins = Project::normalizeOrigins([
        'https://b.example.com',
        'https://a.example.com/',
        'https://B.example.com',
        'nonsense with spaces',
        42,
    ]);

    expect($origins)->toBe(['https://b.example.com', 'https://a.example.com']);
});

test('matching is exact unless a wildcard says otherwise', function () {
    $project = new Project(['allowed_origins' => ['https://shop.example.com']]);

    expect($project->allowsOrigin('https://shop.example.com'))->toBeTrue()
        ->and($project->allowsOrigin('https://shop.example.com/'))->toBeTrue()
        // A different scheme, host or port is a different origin, full stop.
        ->and($project->allowsOrigin('http://shop.example.com'))->toBeFalse()
        ->and($project->allowsOrigin('https://shop.example.com:8443'))->toBeFalse()
        ->and($project->allowsOrigin('https://other.example.com'))->toBeFalse()
        ->and($project->allowsOrigin(''))->toBeFalse();
});

test('a wildcard stands for exactly one subdomain label', function () {
    $project = new Project(['allowed_origins' => ['https://*.example.com']]);

    expect($project->allowsOrigin('https://app.example.com'))->toBeTrue()
        ->and($project->allowsOrigin('https://deep.app.example.com'))->toBeFalse()
        ->and($project->allowsOrigin('https://example.com'))->toBeFalse()
        ->and($project->allowsOrigin('http://app.example.com'))->toBeFalse()
        // The suffix match that is not a subdomain, which is the whole reason
        // this is not a str_ends_with.
        ->and($project->allowsOrigin('https://example.com.attacker.test'))->toBeFalse();
});

test('an unconfigured project allows nothing', function () {
    expect((new Project)->allowsOrigin('https://shop.example.com'))->toBeFalse()
        ->and((new Project(['allowed_origins' => []]))->allowsOrigin('https://shop.example.com'))->toBeFalse();
});
