<?php

use App\Enums\FixJobStatus;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Services\Autofix\AyosClient;
use App\Services\Autofix\AyosException;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Http;

/**
 * Run the dispatcher against a fake queue job so release and fail are visible.
 */
function runDispatcher(FixJob $job, QueueJob $queueJob): void
{
    $dispatcher = new DispatchFixJob($job->uuid);
    $dispatcher->setJob($queueJob);
    $dispatcher->handle(app(AyosClient::class));
}

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $privatePem);

    config([
        'autofix.enabled' => true,
        'autofix.github.app_id' => '123456',
        'autofix.github.private_key' => base64_encode($privatePem),
        'autofix.llm.api_key' => 'sk-ant-test',
    ]);
});

test('an accepted job becomes dispatched', function () {
    fakeAyos();
    fakeRuns();

    $job = ayosJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldNotReceive('fail');

    runDispatcher($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});

test('backpressure releases the queued job and leaves the fix job pending', function () {
    fakeAyos();
    fakeRuns()->failWith = new AyosException('at capacity', statusCode: 429);

    $job = ayosJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(30);
    $queueJob->shouldNotReceive('fail');

    runDispatcher($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Pending)
        ->and($job->fresh()->dispatched_at)->toBeNull()
        ->and($job->fresh()->failure_reason)->toBeNull();
});

test('the release delay grows with each attempt', function () {
    fakeAyos();
    fakeRuns()->failWith = new AyosException('unavailable', statusCode: 503);

    $job = ayosJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $queueJob->shouldReceive('release')->once()->with(120);

    runDispatcher($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Pending);
});

test('a hard failure fails the fix job with the reason recorded', function () {
    fakeAyos();
    fakeRuns()->failWith = new AyosException('The run platform refused the spec (422).', statusCode: 422);

    $job = ayosJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('fail')->once();
    $queueJob->shouldNotReceive('release');

    runDispatcher($job, $queueJob);

    $job = $job->fresh();

    expect($job->status)->toBe(FixJobStatus::Failed)
        ->and($job->failure_reason)->toContain('422')
        ->and($job->completed_at)->not->toBeNull();
});

test('a job that is no longer pending is never dispatched twice', function () {
    fakeAyos();
    $runs = fakeRuns();

    $job = ayosJob();
    $job->forceFill(['status' => FixJobStatus::Running])->save();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldNotReceive('release');

    runDispatcher($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Running)
        ->and($runs->started)->toBe([]);

    Http::assertNothingSent();
});

test('exhausting the retry budget fails the fix job', function () {
    fakeAyos();

    $job = ayosJob();

    (new DispatchFixJob($job->uuid))->failed(new RuntimeException('Ayos was at capacity'));

    $job = $job->fresh();

    expect($job->status)->toBe(FixJobStatus::Failed)
        ->and($job->failure_reason)->toBe('Ayos was at capacity');
});

test('a fix job that already moved on is not failed by a late queue failure', function () {
    fakeAyos();

    $job = ayosJob();
    $job->forceFill(['status' => FixJobStatus::Dispatched])->save();

    (new DispatchFixJob($job->uuid))->failed(new RuntimeException('too late'));

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});
