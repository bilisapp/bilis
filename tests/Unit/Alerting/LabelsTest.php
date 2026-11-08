<?php

use App\Services\Alerting\Labels;

it('hashes a label set independently of key order', function () {
    expect(Labels::hash(['service.name' => 'checkout', 'http.route' => '/pay']))
        ->toBe(Labels::hash(['http.route' => '/pay', 'service.name' => 'checkout']));
});

it('is a 40-character sha1 that tells different label sets apart', function () {
    $hash = Labels::hash(['service.name' => 'checkout']);

    expect($hash)->toHaveLength(40)
        ->and($hash)->toBe(sha1('{"service.name":"checkout"}'))
        ->and($hash)->not->toBe(Labels::hash(['service.name' => 'payments']))
        ->and($hash)->not->toBe(Labels::hash(['host.name' => 'checkout']));
});

it('hashes the ungrouped series as an empty object', function () {
    expect(Labels::hash([]))->toBe(sha1('{}'));
});
