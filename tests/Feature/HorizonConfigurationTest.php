<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('horizon dashboard access is restricted to configured emails', function () {
    config(['horizon.allowed_emails' => ['admin@example.com']]);

    $allowedUser = User::factory()->make(['email' => 'Admin@example.com']);
    $deniedUser = User::factory()->make(['email' => 'other@example.com']);

    expect(Gate::forUser($allowedUser)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($deniedUser)->allows('viewHorizon'))->toBeFalse();
});

test('horizon snapshot is scheduled for metrics', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('horizon:snapshot')
        ->assertSuccessful();
});
