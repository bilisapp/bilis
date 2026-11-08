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

/*
 * The alerting loop: dispatch an evaluation per due rule, then write the
 * heartbeat `/health/alerting` and the scheduler healthcheck read. The
 * two-minute overlap lock means a killed run cannot silence alerting for the
 * default 24 h, and the command returns at once so the minute stays on time.
 */
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(2)->onOneServer()->runInBackground()->description('Dispatch due alert rule evaluations');

/*
 * The default-alert reconciler: make every team match the template catalogue
 * (config/alerting_templates.php). Idempotent — it installs rules for data
 * a team has started sending and upgrades untouched ones, and never brings
 * back what a person turned off. One queued job per team does the work.
 */
Schedule::command('alerts:provision')->everyFifteenMinutes()->withoutOverlapping()->onOneServer()->description('Install and update the default alert rules for every team');

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// MCP access tokens last a day and refresh tokens thirty; without this the
// expired and revoked rows accumulate in oauth_* forever.
Schedule::command('passport:purge')->daily()->description('Delete expired and revoked OAuth tokens and codes');

// Alert history is append-only; `AlertEvent::prunable()` drops it after
// `alerting.events_retention_days`. Any other Prunable model joins here.
Schedule::command('model:prune')->daily()->description('Prune expired alert history and other prunable models');
