<?php

use App\Jobs\EvaluateAlertRule;
use App\Models\AlertRule;
use App\Services\Alerting\AlertingHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:37', 'UTC'));
});

it('dispatches only due rules and moves each to its next aligned slot', function () {
    $due = AlertRule::factory()->create(['next_evaluation_at' => now()->subMinutes(10)]);
    $never = AlertRule::factory()->create(['next_evaluation_at' => null]);
    $fiveMinute = AlertRule::factory()->create(['interval_seconds' => 300, 'next_evaluation_at' => now()]);
    $later = AlertRule::factory()->create(['next_evaluation_at' => now()->addMinute()]);
    $disabled = AlertRule::factory()->disabled()->create(['next_evaluation_at' => now()->subMinute()]);

    $this->artisan('alerts:evaluate')->assertSuccessful();

    $minuteAt = CarbonImmutable::parse('2026-11-08 11:59:00', 'UTC')->getTimestamp();
    $fiveMinuteAt = CarbonImmutable::parse('2026-11-08 11:55:00', 'UTC')->getTimestamp();

    Queue::assertPushedTimes(EvaluateAlertRule::class, 3);
    Queue::assertPushedOn('alerts', EvaluateAlertRule::class);

    foreach ([[$due, $minuteAt], [$never, $minuteAt], [$fiveMinute, $fiveMinuteAt]] as [$rule, $at]) {
        Queue::assertPushed(EvaluateAlertRule::class, fn (EvaluateAlertRule $job): bool => $job->ruleId === $rule->id && $job->evaluatedAt === $at);
    }

    Queue::assertNotPushed(EvaluateAlertRule::class, fn (EvaluateAlertRule $job): bool => in_array($job->ruleId, [$later->id, $disabled->id], true));

    // A ten-minute outage evaluates once: the next slot is after now, not after the missed one.
    expect($due->fresh()->next_evaluation_at->toDateTimeString())->toBe('2026-11-08 12:01:00')
        ->and($never->fresh()->next_evaluation_at->toDateTimeString())->toBe('2026-11-08 12:01:00')
        ->and($fiveMinute->fresh()->next_evaluation_at->toDateTimeString())->toBe('2026-11-08 12:05:00')
        ->and($later->fresh()->next_evaluation_at->toDateTimeString())->toBe('2026-11-08 12:01:37');
});

it('writes the heartbeat on every run, rules or not', function () {
    $this->artisan('alerts:evaluate')->assertSuccessful();

    expect(Cache::get(AlertingHeartbeat::KEY))->toBe(now()->getTimestamp());
});

it('does nothing while alerting is disabled', function () {
    config(['alerting.enabled' => false]);
    AlertRule::factory()->create(['next_evaluation_at' => now()->subMinute()]);

    $this->artisan('alerts:evaluate')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(Cache::has(AlertingHeartbeat::KEY))->toBeFalse();
});

it('is scheduled every minute, on one server, with a short overlap lock', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'alerts:evaluate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(2)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('has Horizon consume the alerts queue', function () {
    expect(config('horizon.defaults.supervisor-1.queue'))->toContain('alerts');
});

it('fails the heartbeat check only when enabled and stale', function () {
    $this->artisan('alerts:heartbeat-check')->assertFailed();

    Cache::forever(AlertingHeartbeat::KEY, now()->subSeconds(200)->getTimestamp());
    $this->artisan('alerts:heartbeat-check')->assertFailed();

    Cache::forever(AlertingHeartbeat::KEY, now()->subSeconds(30)->getTimestamp());
    $this->artisan('alerts:heartbeat-check')->assertSuccessful();

    Cache::forget(AlertingHeartbeat::KEY);
    config(['alerting.enabled' => false]);
    $this->artisan('alerts:heartbeat-check')->assertSuccessful();
});
