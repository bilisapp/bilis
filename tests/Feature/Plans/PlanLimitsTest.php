<?php

declare(strict_types=1);

use App\Services\Plans\PlanLimits;

it('publishes the configured Free allowances', function () {
    config([
        'plans.free.projects_per_team' => 4,
        'plans.free.members_per_team' => 9,
        'plans.free.events_per_day' => 2_000_000,
        'plans.warn_at_percent' => 70,
    ]);

    $limits = app(PlanLimits::class);

    expect($limits->projectsPerTeam())->toBe(4)
        ->and($limits->membersPerTeam())->toBe(9)
        ->and($limits->eventsPerDay())->toBe(2_000_000)
        ->and($limits->warnAtPercent())->toBe(70);
});

it('reads retention and the request ceiling from where they are already promised', function () {
    // Neither is duplicated in config/plans.php: retention is what the legal
    // pages render, and the per-minute ceiling is what the limiter enforces.
    config([
        'legal.log_retention_days' => 45,
        'security.ingest_rate_limit' => 600,
    ]);

    $limits = app(PlanLimits::class);

    expect($limits->retentionDays())->toBe(45)
        ->and($limits->requestsPerMinute())->toBe(600);

    expect(config('plans'))
        ->not->toHaveKey('free.retention_days')
        ->not->toHaveKey('free.requests_per_minute');
});

it('serialises the whole plan for props and Blade', function () {
    expect(app(PlanLimits::class)->free())->toBe([
        'projectsPerTeam' => (int) config('plans.free.projects_per_team'),
        'membersPerTeam' => (int) config('plans.free.members_per_team'),
        'eventsPerDay' => (int) config('plans.free.events_per_day'),
        'retentionDays' => (int) config('legal.log_retention_days'),
        'requestsPerMinute' => (int) config('security.ingest_rate_limit'),
        'warnAtPercent' => (int) config('plans.warn_at_percent'),
    ]);
});
