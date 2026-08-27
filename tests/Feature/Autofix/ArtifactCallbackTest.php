<?php

use App\Enums\FixJobStatus;
use App\Http\Middleware\VerifyAyosSignature;
use App\Jobs\ValidateAndPublishFix;
use App\Models\FixJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * POST a signed artifact the way Ayos would.
 *
 * @param  array<string, mixed>  $payload
 */
function postArtifact(array $payload, ?string $secret = 'shared-secret', ?int $timestamp = null): TestResponse
{
    $body = (string) json_encode($payload);

    /*
     * The signature covers the body exactly as it goes over the wire, so the
     * request is built from that raw string rather than from an array the test
     * client would re-encode.
     */
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    if ($secret !== null) {
        $server['HTTP_X_AYOS_TIMESTAMP'] = (string) ($timestamp ?? now()->getTimestamp());
        $server['HTTP_X_AYOS_SIGNATURE'] = VerifyAyosSignature::signature($body, $secret);
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
    config(['autofix.ayos.shared_secret' => 'shared-secret']);

    Queue::fake();
});

test('an unsigned callback is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), secret: null)->assertUnauthorized();

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});

test('a callback signed with the wrong secret is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), secret: 'wrong-secret')->assertUnauthorized();
});

test('a callback with a stale timestamp is rejected', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job), timestamp: now()->subHour()->getTimestamp())->assertUnauthorized();
});

test('an unknown job id is a 404', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact([...artifact($job), 'job_id' => 'not-a-job'])->assertNotFound();
});

test('a done artifact moves the job to validating and queues the validator', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job))
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

    postArtifact([...artifact($job, $reported), 'report' => null])
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

    postArtifact(artifact($job, 'failed'));

    expect($job->fresh()->failure_reason)->toBe('Guarded the missing total.');
});

test('a redelivered artifact is a no-op', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job))->assertOk();

    $first = $job->fresh();

    postArtifact([...artifact($job), 'status' => 'failed'])
        ->assertOk()
        ->assertJson(['status' => 'validating', 'applied' => false]);

    $job = $job->fresh();

    expect($job->status)->toBe(FixJobStatus::Validating)
        ->and($job->updated_at->eq($first->updated_at))->toBeTrue();

    Queue::assertPushed(ValidateAndPublishFix::class, 1);
});

test('an artifact for a job that already opened a pull request is a no-op', function () {
    $job = FixJob::factory()->prOpened()->create();

    postArtifact(artifact($job, 'failed'))
        ->assertOk()
        ->assertJson(['status' => 'pr_opened', 'applied' => false]);

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);
});

test('an artifact with an unknown status is refused', function () {
    $job = FixJob::factory()->dispatched()->create();

    postArtifact(artifact($job, 'merged'))->assertStatus(422);

    expect($job->fresh()->status)->toBe(FixJobStatus::Dispatched);
});

test('an artifact without a job id is refused', function () {
    postArtifact(['status' => 'done'])->assertStatus(422);
});

test('the validation job is a no-op until the write path lands', function () {
    $job = FixJob::factory()->create(['status' => FixJobStatus::Validating, 'diff' => "--- a\n+++ b\n"]);

    (new ValidateAndPublishFix($job->uuid))->handle();

    expect($job->fresh()->status)->toBe(FixJobStatus::Validating);
});

test('the validation job tolerates a job that has been deleted', function () {
    (new ValidateAndPublishFix('gone'))->handle();
})->throwsNoExceptions();
