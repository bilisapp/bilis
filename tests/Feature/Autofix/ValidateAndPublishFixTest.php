<?php

use App\Enums\FixJobStatus;
use App\Jobs\DispatchFixJob;
use App\Jobs\ValidateAndPublishFix;
use App\Models\FixJob;
use App\Services\Autofix\DiffValidator;
use App\Services\Autofix\PullRequestPublisher;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * Run the validator job against a fake queue job so release and fail show up.
 */
function runValidator(FixJob $job, ?QueueJob $queueJob = null): void
{
    $queued = new ValidateAndPublishFix($job->uuid);

    if ($queueJob !== null) {
        $queued->setJob($queueJob);
    }

    $queued->handle(app(DiffValidator::class), app(PullRequestPublisher::class));
}

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $privatePem);

    config([
        'autofix.enabled' => true,
        'autofix.github.app_id' => '123456',
        'autofix.github.private_key' => base64_encode($privatePem),
    ]);
});

/**
 * A job sitting in `validating` with a diff Ayos handed back.
 */
function validatingJob(string $diff, ?array $report = null): FixJob
{
    $job = ayosJob();

    $job->forceFill([
        'status' => FixJobStatus::Validating,
        'diff' => $diff,
        'report' => $report ?? ['summary' => 'Guarded the missing key.', 'tests' => ['passed' => true]],
    ])->save();

    return $job;
}

test('a valid diff is published as a pull request', function () {
    fakeGitHubRepository(billingFiles());

    $job = validatingJob(billingDiff());

    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened)
        ->and($job->fresh()->pr_number)->toBe(42);
});

test('a rejected diff never reaches a GitHub write', function () {
    fakeGitHubRepository(billingFiles());

    $job = validatingJob("--- a/.github/workflows/ci.yml\n+++ b/.github/workflows/ci.yml\n@@ -1 +1 @@\n-a\n+b\n");

    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::Rejected)
        ->and($job->fresh()->failure_reason)->toBe('denylisted_path: .github/workflows/ci.yml')
        ->and($job->fresh()->completed_at)->not->toBeNull();

    Http::assertNothingSent();
});

test('a stale diff is sent back around once against a fresh base', function () {
    Bus::fake([DispatchFixJob::class]);

    fakeGitHubRepository(['app/Billing.php' => "<?php\n// already fixed upstream\n"]);

    $job = validatingJob(billingDiff());

    runValidator($job);

    $job->refresh();

    expect($job->status)->toBe(FixJobStatus::Pending)
        ->and($job->redispatch_count)->toBe(1)
        ->and($job->diff)->toBeNull()
        ->and($job->report)->toBeNull()
        ->and($job->failure_reason)->toStartWith('stale_base');

    Bus::assertDispatched(DispatchFixJob::class, fn (DispatchFixJob $dispatched) => $dispatched->uuid === $job->uuid);
});

test('a stale diff on the second attempt is rejected for good', function () {
    Bus::fake([DispatchFixJob::class]);

    fakeGitHubRepository(['app/Billing.php' => "<?php\n// already fixed upstream\n"]);

    $job = validatingJob(billingDiff());
    $job->forceFill(['redispatch_count' => 1])->save();

    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::Rejected)
        ->and($job->fresh()->failure_reason)->toStartWith('diff_does_not_apply');

    Bus::assertNotDispatched(DispatchFixJob::class);
});

test('running the job twice opens only one pull request', function () {
    fakeGitHubRepository(billingFiles());

    $job = validatingJob(billingDiff());

    runValidator($job);
    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);

    /*
     * One pass through the write path: a read token, the head, the tree, one
     * blob read, a write token, and then blob, tree, commit, ref and pull
     * request. The second run adds nothing.
     */
    Http::assertSentCount(10);
});

test('a rejected job is left alone on a second run', function () {
    fakeGitHubRepository(billingFiles());

    $job = validatingJob(billingDiff());
    $job->forceFill(['status' => FixJobStatus::Rejected, 'failure_reason' => 'empty_diff'])->save();

    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::Rejected)
        ->and($job->fresh()->failure_reason)->toBe('empty_diff');

    Http::assertNothingSent();
});

test('a transient GitHub failure releases the queued job and keeps the fix job validating', function () {
    fakeGitHubRepository(billingFiles(), [
        'api.github.com/repos/acme/app/commits/*' => Http::response(['message' => 'Bad gateway'], 502),
    ]);

    $job = validatingJob(billingDiff());

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(30);
    $queueJob->shouldNotReceive('fail');

    runValidator($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Validating);
});

test('a terminal GitHub failure fails the fix job with the reason recorded', function () {
    fakeGitHubRepository(billingFiles(), [
        'api.github.com/repos/acme/app/commits/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $job = validatingJob(billingDiff());

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldReceive('fail')->once();

    runValidator($job, $queueJob);

    expect($job->fresh()->status)->toBe(FixJobStatus::Failed)
        ->and($job->fresh()->failure_reason)->toContain('404')
        ->and($job->fresh()->completed_at)->not->toBeNull();
});

test('the retry budget running out fails the fix job', function () {
    $job = validatingJob(billingDiff());

    (new ValidateAndPublishFix($job->uuid))->failed(new RuntimeException('GitHub is down'));

    expect($job->fresh()->status)->toBe(FixJobStatus::Failed)
        ->and($job->fresh()->failure_reason)->toBe('GitHub is down');
});

test('an empty diff records a no-change outcome without asking GitHub anything', function () {
    fakeGitHubRepository();

    $job = validatingJob('   ');

    runValidator($job);

    expect($job->fresh()->status)->toBe(FixJobStatus::NoChange)
        ->and($job->fresh()->failure_reason)->toBeNull()
        ->and($job->fresh()->completed_at)->not->toBeNull();

    Http::assertNothingSent();
});
