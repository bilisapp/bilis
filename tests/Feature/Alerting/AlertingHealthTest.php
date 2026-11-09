<?php

use App\Services\Alerting\AlertingHeartbeat;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->freezeSecond();
});

it('is ok while the heartbeat is fresh', function () {
    Cache::forever(AlertingHeartbeat::KEY, now()->subSeconds(42)->getTimestamp());

    $this->getJson(route('health.alerting'))
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'lag' => 42])
        ->assertHeaderMissing('Set-Cookie');
});

it('is unavailable when the heartbeat is older than three minutes', function () {
    Cache::forever(AlertingHeartbeat::KEY, now()->subSeconds(181)->getTimestamp());

    $this->getJson('/health/alerting')
        ->assertServiceUnavailable()
        ->assertExactJson(['status' => 'stale', 'lag' => 181]);
});

it('is unavailable when there has never been a heartbeat', function () {
    $this->getJson('/health/alerting')
        ->assertServiceUnavailable()
        ->assertJson(['status' => 'missing']);
});

it('reports disabled alerting as healthy', function () {
    config(['alerting.enabled' => false]);

    $this->getJson('/health/alerting')
        ->assertOk()
        ->assertExactJson(['status' => 'disabled']);
});
