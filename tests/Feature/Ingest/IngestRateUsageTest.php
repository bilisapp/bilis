<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\Ingest\IngestRateUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'security.ingest_rate_limit' => 1200,
    ]);

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->plainTextKey = 'bilis_'.str_repeat('a', 40);
    $this->project = Project::factory()->create(['name' => 'Api', 'slug' => 'api']);
    $this->apiKey = ProjectApiKey::factory()
        ->forProject($this->project)
        ->withPlainKey($this->plainTextKey)
        ->create(['name' => 'Production collector']);
});

/**
 * Post one minimal record with the given key, through the real throttle.
 */
function postIngest(string $key): void
{
    test()->withToken($key)->postJson('/api/v1/ingest', ['message' => 'hello'])->assertStatus(202);
}

/**
 * The service, resolved from the container.
 */
function usage(): IngestRateUsage
{
    return app(IngestRateUsage::class);
}

test('the service reads the counter the throttle middleware actually writes', function () {
    postIngest($this->plainTextKey);
    postIngest($this->plainTextKey);
    postIngest($this->plainTextKey);

    $result = usage()->forKeys(ProjectApiKey::with('project')->get());

    expect($result['limit'])->toBe(1200)
        ->and($result['disabled'])->toBeFalse()
        ->and($result['keys'])->toHaveCount(1);

    // The whole point of the panel: a real POST has to move this number.
    expect($result['keys'][0]['attempts'])->toBe(3)
        ->and($result['keys'][0]['remaining'])->toBe(1197)
        ->and($result['keys'][0]['project'])->toBe('Api')
        ->and($result['keys'][0]['projectSlug'])->toBe('api')
        ->and($result['keys'][0]['name'])->toBe('Production collector');
});

test('the derived cache key matches the throttle middleware, character for character', function () {
    postIngest($this->plainTextKey);

    /*
     * ThrottleRequests hashes the limiter name with the Limit's bucket before
     * RateLimiter ever sees it: md5($limiterName . $limit->key). Asserted
     * directly so a framework change to that shape fails here rather than
     * silently zeroing the dashboard.
     */
    $bucket = IngestRateUsage::bucketForKeyHash(ProjectApiKey::hashKey($this->plainTextKey));

    expect($bucket)->toBe('ingest:key:'.hash('sha256', $this->plainTextKey))
        ->and(IngestRateUsage::counterKey($bucket))->toBe(md5('ingest'.$bucket))
        ->and((int) RateLimiter::attempts(IngestRateUsage::counterKey($bucket)))->toBe(1);
});

test('a key nobody has used reports a full budget', function () {
    $idle = ProjectApiKey::factory()
        ->forProject(Project::factory()->create())
        ->withPlainKey('bilis_'.str_repeat('b', 40))
        ->create();

    $result = usage()->forKeys(ProjectApiKey::with('project')->whereKey($idle->id)->get());

    expect($result['keys'][0]['attempts'])->toBe(0)
        ->and($result['keys'][0]['remaining'])->toBe(1200);
});

test('one key spending its budget never shows up on another', function () {
    $otherPlainKey = 'bilis_'.str_repeat('c', 40);
    ProjectApiKey::factory()
        ->forProject(Project::factory()->create(['name' => 'Worker', 'slug' => 'worker']))
        ->withPlainKey($otherPlainKey)
        ->create(['name' => 'Worker collector']);

    postIngest($this->plainTextKey);
    postIngest($this->plainTextKey);
    postIngest($otherPlainKey);

    $byName = collect(usage()->forKeys(ProjectApiKey::with('project')->get())['keys'])
        ->keyBy('name');

    expect($byName['Production collector']['attempts'])->toBe(2)
        ->and($byName['Worker collector']['attempts'])->toBe(1);
});

test('a disabled limiter counts nothing and says so', function () {
    config(['security.ingest_rate_limit' => 0]);

    postIngest($this->plainTextKey);

    $result = usage()->forKeys(ProjectApiKey::with('project')->get());

    expect($result['disabled'])->toBeTrue()
        ->and($result['limit'])->toBe(0)
        ->and($result['keys'][0]['attempts'])->toBe(0)
        ->and($result['keys'][0]['remaining'])->toBe(0);
});
