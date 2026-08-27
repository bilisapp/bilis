<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes()->description('Record Horizon queue metrics');

/*
 * Look for production errors worth an automated fix attempt. The command is a
 * no-op unless autofix is enabled and a repository has opted in, and the
 * trigger's own budgets decide whether anything is actually raised.
 */
Schedule::command('autofix:scan')->everyFiveMinutes()->withoutOverlapping()->description('Scan recent errors for autofix candidates');

/*
 * Ask the logs whether a merged fix actually worked. Hourly is plenty: the
 * grace window before a job can be ruled on is measured in hours, and each
 * job is ruled on exactly once.
 */
Schedule::command('autofix:verify')->hourly()->withoutOverlapping()->description('Verify merged autofix pull requests against the logs');

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');
