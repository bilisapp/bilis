<?php

use App\Enums\FixJobStatus;
use App\Models\FixJob;
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
