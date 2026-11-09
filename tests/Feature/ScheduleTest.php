<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('expired oauth tokens are purged daily', function () {
    $purge = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'passport:purge'));

    expect($purge)->not->toBeNull()
        ->and($purge->expression)->toBe('0 0 * * *');
});

test('default alerts are provisioned every fifteen minutes without overlapping', function () {
    $provision = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'alerts:provision'));

    expect($provision)->not->toBeNull()
        ->and($provision->expression)->toBe('*/15 * * * *')
        ->and($provision->withoutOverlapping)->toBeTrue();
});
