<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('expired oauth tokens are purged daily', function () {
    $purge = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'passport:purge'));

    expect($purge)->not->toBeNull()
        ->and($purge->expression)->toBe('0 0 * * *');
});
