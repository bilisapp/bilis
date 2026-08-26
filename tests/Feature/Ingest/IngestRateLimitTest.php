<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);
    RateLimiter::clear('ingest');

    $this->plainTextKey = 'bilis_'.str_repeat('d', 40);
    $this->project = Project::factory()->create();
    $this->apiKey = ProjectApiKey::factory()
        ->forProject($this->project)
        ->withPlainKey($this->plainTextKey)
        ->create();
});

/**
 * Post one minimal record with the given key.
 */
function ingestOnce(string $key): TestResponse
{
    return test()->withToken($key)->postJson('/api/v1/ingest', ['message' => 'hello']);
}

test('an over-eager client is throttled with a retryable 429', function () {
    config(['security.ingest_rate_limit' => 2]);

    ingestOnce($this->plainTextKey)->assertStatus(202);
    ingestOnce($this->plainTextKey)->assertStatus(202);

    ingestOnce($this->plainTextKey)
        ->assertStatus(429)
        // The client is told when to come back, never that it sent bad data.
        ->assertHeader('Retry-After');
});

test('the limit is counted per api key, so one project cannot starve another', function () {
    config(['security.ingest_rate_limit' => 1]);

    $otherKey = 'bilis_'.str_repeat('e', 40);
    ProjectApiKey::factory()
        ->forProject(Project::factory()->create())
        ->withPlainKey($otherKey)
        ->create();

    ingestOnce($this->plainTextKey)->assertStatus(202);
    ingestOnce($this->plainTextKey)->assertStatus(429);

    ingestOnce($otherKey)->assertStatus(202);
});

test('the limiter can be disabled', function () {
    config(['security.ingest_rate_limit' => 0]);

    foreach (range(1, 5) as $ignored) {
        ingestOnce($this->plainTextKey)->assertStatus(202);
    }
});

test('a request carrying no key at all is bucketed by address', function () {
    config(['security.ingest_rate_limit_unauthenticated' => 1]);

    $this->postJson('/api/v1/ingest', ['message' => 'hello'])->assertStatus(401);
    $this->postJson('/api/v1/ingest', ['message' => 'hello'])->assertStatus(429);
});

test('a wrong key gets its own bucket and never spends a valid key\'s budget', function () {
    config(['security.ingest_rate_limit' => 1]);

    ingestOnce('bilis_'.str_repeat('z', 40))->assertStatus(401);

    ingestOnce($this->plainTextKey)->assertStatus(202);
});
