<?php

namespace App\Services\Plans;

use Illuminate\Contracts\Config\Repository;

/**
 * The published allowances of the hosted Free plan.
 *
 * One reader for six numbers, so the pricing page, the docs trip-wire, the
 * dashboard card and the team settings page cannot drift apart. Four come
 * from `config/plans.php`; retention comes from `legal.log_retention_days`
 * and the per-key request ceiling from `security.ingest_rate_limit`, because
 * those two are already promised (legal pages) and already enforced (the
 * ingest limiter) elsewhere and must not be restated.
 *
 * Every limit here is soft. Nothing in the ingest path reads this class, and
 * nothing in the UI blocks on it — going over is reported, never punished.
 *
 * @phpstan-type FreePlan array{projectsPerTeam: int, membersPerTeam: int, eventsPerDay: int, retentionDays: int, requestsPerMinute: int, warnAtPercent: int}
 */
class PlanLimits
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Projects one team may create on the Free plan.
     */
    public function projectsPerTeam(): int
    {
        return (int) $this->config->get('plans.free.projects_per_team', 3);
    }

    /**
     * People in one team, the owner included.
     */
    public function membersPerTeam(): int
    {
        return (int) $this->config->get('plans.free.members_per_team', 5);
    }

    /**
     * Log records plus spans accepted in one UTC day.
     */
    public function eventsPerDay(): int
    {
        return (int) $this->config->get('plans.free.events_per_day', 100_000);
    }

    /**
     * How long logs and spans are kept, from the retention the legal pages promise.
     */
    public function retentionDays(): int
    {
        return (int) $this->config->get('legal.log_retention_days', 30);
    }

    /**
     * Ingest requests per minute per API key, from the limiter's own setting.
     */
    public function requestsPerMinute(): int
    {
        return (int) $this->config->get('security.ingest_rate_limit', 1200);
    }

    /**
     * The share of an allowance at which the app starts warning.
     */
    public function warnAtPercent(): int
    {
        return (int) $this->config->get('plans.warn_at_percent', 80);
    }

    /**
     * The whole Free plan as a serialisable array, for props and Blade.
     *
     * @return FreePlan
     */
    public function free(): array
    {
        return [
            'projectsPerTeam' => $this->projectsPerTeam(),
            'membersPerTeam' => $this->membersPerTeam(),
            'eventsPerDay' => $this->eventsPerDay(),
            'retentionDays' => $this->retentionDays(),
            'requestsPerMinute' => $this->requestsPerMinute(),
            'warnAtPercent' => $this->warnAtPercent(),
        ];
    }
}
