<?php

use App\Enums\FixJobStatus;
use App\Http\Middleware\VerifyAyosSignature;
use App\Jobs\ValidateAndPublishFix;
use App\Models\FixJob;
use App\Services\Autofix\RunKeyPair;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Give a job the keypair its run would have been started with.
 *
 * One pair per job, the public half on the row: that is the whole of the
 * authentication story now, and it is why a signature from another run cannot
 * speak for this one.
 */
function runKeys(FixJob $job): RunKeyPair
{
    $keys = RunKeyPair::mint();

    $job->forceFill(['ayos_public_key' => $keys->publicKey])->save();

    return $keys;
}

/**
 * POST a signed artifact the way a run would.
 *
 * @param  array<string, mixed>  $payload
 */
function postArtifact(array $payload, ?RunKeyPair $keys = null, ?int $timestamp = null): TestResponse
{
    $body = (string) json_encode($payload);

    /*
     * The signature covers the timestamp and the body exactly as they go over
     * the wire, so the request is built from that raw string rather than from
     * an array the test client would re-encode.
     */
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($keys !== null) {
        $signedAt = (string) ($timestamp ?? now()->getTimestamp());
        $secretKey = sodium_crypto_sign_secretkey(
            sodium_crypto_sign_seed_keypair((string) base64_decode($keys->signingKey, true)),
        );

        $server['HTTP_X_AYOS_TIMESTAMP'] = $signedAt;
        $server['HTTP_X_AYOS_SIGNATURE'] = VerifyAyosSignature::SIGNATURE_PREFIX.base64_encode(
            sodium_crypto_sign_detached($signedAt.'.'.$body, $secretKey),
        );
    }

    return test()->call('POST', route('api.internal.autofix.artifacts'), [], [], [], $server, $body);
}

/**
 * A minimal but realistic artifact.
 *
 * @return array<string, mixed>
 */
function artifact(FixJob $job, string $status = 'done'): array
{
    return [
        'job_id' => $job->uuid,
        'status' => $status,
        'diff' => '--- a/app/Billing.php'."\n".'+++ b/app/Billing.php'."\n".'@@'."\n".'-    $total'."\n".'+    $total ?? 0'."\n",
        'report' => [
            'summary' => 'Guarded the missing total.',
            'files_touched' => ['app/Billing.php'],
            'tests' => ['cmd' => 'php artisan test', 'passed' => true, 'output_tail' => 'OK'],
        ],
        'events' => [
            ['seq' => 1, 'ts' => '2026-08-27T10:00:00Z', 'type' => 'phase', 'data' => ['phase' => 'cloning']],
        ],
    ];
}

beforeEach(function () {
    Queue::fake();
});

test('an unsigned callback is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();
    runKeys($job);

    postArtifact(artifact($job), keys: null)->assertUnauthorized();

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});

/*
 * The per-run scheme in one test: a signature that is cryptographically
 * perfect, made with a key that simply belongs to a different job, buys
 * nothing. Under the retired shared secret every run's key was every job's key.
 */
test('a callback signed with another run key is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();
    runKeys($job);

    postArtifact(artifact($job), keys: RunKeyPair::mint())->assertUnauthorized();
});

test('a callback for a job that never started a run is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), keys: RunKeyPair::mint())->assertUnauthorized();
});

test('a callback with a stale timestamp is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), runKeys($job), timestamp: now()->subHour()->getTimestamp())
        ->assertUnauthorized();
});

/*
 * A 401 rather than a 404, and deliberately so: the job id selects the key, so
 * an id nobody can sign for is indistinguishable from a bad signature — and
 * saying which would tell an unauthenticated caller which job ids exist.
 */
test('an unknown job id is rejected without saying so', function () {
    $job = FixJob::factory()->dispatched()->create();
    $keys = runKeys($job);

    postArtifact([...artifact($job), 'job_id' => 'not-a-job'], $keys)->assertUnauthorized();
});

test('a done artifact moves the job to validating and queues the validator', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), runKeys($job))
        ->assertOk()
        ->assertJson(['status' => 'validating', 'applied' => true]);

    $job = $job->fresh();

    expect($job->status)->toBe(FixJobStatus::Validating)
        ->and($job->diff)->toContain('app/Billing.php')
        ->and($job->report['summary'])->toBe('Guarded the missing total.')
        ->and($job->events)->toHaveCount(1)
        ->and($job->completed_at)->toBeNull();

    Queue::assertPushed(ValidateAndPublishFix::class, fn (ValidateAndPublishFix $queued): bool => $queued->uuid === $job->uuid);
});

test('a terminal artifact status is recorded with a reason and a completion time', function (string $reported, FixJobStatus $expected) {
    $job = FixJob::factory()->running()->create();

    postArtifact([...artifact($job, $reported), 'report' => null], runKeys($job))
        ->assertOk()
        ->assertJson(['status' => $expected->value, 'applied' => true]);

    $job = $job->fresh();

    expect($job->status)->toBe($expected)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->failure_reason)->not->toBeNull();

    Queue::assertNotPushed(ValidateAndPublishFix::class);
})->with([
    ['failed', FixJobStatus::Failed],
    ['cancelled', FixJobStatus::Cancelled],
    ['timeout', FixJobStatus::Timeout],
]);

test('a terminal artifact prefers the agent summary as its reason', function () {
    $job = FixJob::factory()->running()->create();

    postArtifact(artifact($job, 'failed'), runKeys($job));

    expect($job->fresh()->failure_reason)->toBe('Guarded the missing total.');
});

test('a terminal artifact prefers the reported error over the agent summary', function () {
    $job = FixJob::factory()->running()->create();

    $artifact = artifact($job, 'failed');
    $artifact['report']['error'] = 'diff exceeds max_diff_lines (1072791 > 800)';

    postArtifact($artifact, runKeys($job));

    expect($job->fresh()->failure_reason)->toBe('diff exceeds max_diff_lines (1072791 > 800)');
});

test('a redelivered artifact is a no-op', function () {
    $job = FixJob::factory()->dispatched()->create();

    $keys = runKeys($job);

    postArtifact(artifact($job), $keys)->assertOk();

    $first = $job->fresh();

    postArtifact([...artifact($job), 'status' => 'failed'], $keys)
        ->assertOk()
        ->assertJson(['status' => 'validating', 'applied' => false]);

    $job = $job->fresh();

    expect($job->status)->toBe(FixJobStatus::Validating)
        ->and($job->updated_at->eq($first->updated_at))->toBeTrue();

    Queue::assertPushed(ValidateAndPublishFix::class, 1);
});

test('an artifact for a job that already opened a pull request is a no-op', function () {
    $job = FixJob::factory()->prOpened()->create();

    postArtifact(artifact($job, 'failed'), runKeys($job))
        ->assertOk()
        ->assertJson(['status' => 'pr_opened', 'applied' => false]);

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);
});

test('an artifact with an unknown status is refused', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job, 'merged'), runKeys($job))->assertStatus(422);

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});

/*
 * Refused by the middleware rather than by validation: without a job id there
 * is no key to verify against, so the request never reaches the controller.
 */
test('an artifact without a job id is refused', function () {
    postArtifact(['status' => 'done'], RunKeyPair::mint())->assertUnauthorized();
});

test('the validation job leaves a job it no longer owns alone', function () {
    Http::fake();

    $job = FixJob::factory()->prOpened()->create();

    app()->call([new ValidateAndPublishFix($job->uuid), 'handle']);

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);

    Http::assertNothingSent();
});

test('the validation job tolerates a job that has been deleted', function () {
    app()->call([new ValidateAndPublishFix('gone'), 'handle']);
})->throwsNoExceptions();
