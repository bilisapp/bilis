<?php

use App\Models\AlertEvent;

it('prunes alert history past the retention window', function () {
    config(['alerting.events_retention_days' => 90]);

    $old = AlertEvent::factory()->create(['occurred_at' => now()->subDays(91)]);
    $kept = AlertEvent::factory()->create(['occurred_at' => now()->subDays(89)]);

    expect((new AlertEvent)->prunable()->pluck('id')->all())->toBe([$old->id]);

    $this->artisan('model:prune', ['--model' => [AlertEvent::class]])->assertSuccessful();

    expect(AlertEvent::query()->pluck('id')->all())->toBe([$kept->id]);
});
