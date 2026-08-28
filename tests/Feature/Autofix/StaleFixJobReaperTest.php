<?php

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use App\Services\Autofix\AyosException;
use App\Services\Autofix\RunStatus;
use App\Services\Autofix\StaleFixJobReaper;
use Illuminate\Support\Carbon;

it('fails dispatched jobs ayos never answered for', function () {
    $job = FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(40),
    ]);

    $reaped = app(StaleFixJobReaper::class)->reap();

    expect($reaped)->toHaveCount(1)
        ->and($reaped[0]->is($job))->toBeTrue();

    $job->refresh();

    expect($job->status)->toBe(FixJobStatus::Failed)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->failure_reason)->toContain('declared lost');
});

it('leaves jobs inside the timeout window alone', function () {
    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(10),
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty()
        ->and(FixJob::query()->where('status', FixJobStatus::Dispatched)->count())->toBe(1);
});

it('never touches pending or terminal jobs', function () {
    $old = Carbon::now()->subHours(3);

    FixJob::factory()->create(['status' => FixJobStatus::Pending, 'dispatched_at' => null]);
    FixJob::factory()->merged()->create(['dispatched_at' => $old]);
    FixJob::factory()->failed()->create(['dispatched_at' => $old]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});

it('respects the configured job timeout', function () {
    config()->set('autofix.defaults.timeout_s', 3600);

    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(40),
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});

/*
 * The signal that only exists because Ayos stopped being a service. A run the
 * platform reports as finished, which has not delivered an artifact, is a lost
 * job right now — there is nothing to wait for. Before, an in-flight job could
 * only be timed out.
 */
it('fails a job whose run has ended without delivering an artifact', function () {
    $runs = fakeRuns();
    $runs->status = RunStatus::Finished;

    $job = FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(5),
        'ayos_run_id' => 'run-42',
    ]);

    $reaped = app(StaleFixJobReaper::class)->reap();

    expect($reaped)->toHaveCount(1);

    expect($job->fresh()->status)->toBe(FixJobStatus::Failed)
        ->and($job->fresh()->failure_reason)->toContain('without delivering an artifact');
});

it('leaves a job whose run is still alive alone', function () {
    $runs = fakeRuns();
    $runs->status = RunStatus::Running;

    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(5),
        'ayos_run_id' => 'run-42',
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});

/*
 * A run that has been accepted but not yet scheduled can report as finished for
 * a moment. Reaping a job that is about to start is worse than reaping one a
 * minute late.
 */
it('does not believe a run status inside the start-up grace period', function () {
    $runs = fakeRuns();
    $runs->status = RunStatus::Finished;

    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subSeconds(5),
        'ayos_run_id' => 'run-42',
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});

/*
 * A platform that cannot be asked is not evidence that a run is dead. Reaping
 * on a failed status call would turn one API blip into a wave of failed jobs —
 * the deadline remains the answer for those.
 */
it('does not reap when the platform cannot be reached', function () {
    $runs = fakeRuns();
    $runs->failWith = new AyosException('scaleway is down', statusCode: 503);

    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(5),
        'ayos_run_id' => 'run-42',
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});

it('still reaps past the deadline even when the run claims to be alive', function () {
    $runs = fakeRuns();
    $runs->status = RunStatus::Running;

    $job = FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(40),
        'ayos_run_id' => 'run-42',
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toHaveCount(1)
        ->and($job->fresh()->failure_reason)->toContain('declared lost');
});

it('ignores a job that never recorded a run id', function () {
    fakeRuns()->status = RunStatus::Finished;

    FixJob::factory()->dispatched()->create([
        'dispatched_at' => now()->subMinutes(5),
        'ayos_run_id' => null,
    ]);

    expect(app(StaleFixJobReaper::class)->reap())->toBeEmpty();
});
