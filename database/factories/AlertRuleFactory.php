<?php

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Enums\AlertRuleHealth;
use App\Enums\AlertSeverity;
use App\Enums\NoDataState;
use App\Models\AlertRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    /**
     * Define the model's default state: "more than 25 error logs in 5 minutes
     * from checkout", the rule most teams write first.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->randomElement([
                'Checkout error spike',
                'Payments API errors',
                'Worker log flood',
                'Auth failures',
            ]),
            'source' => 'logs',
            'project_ids' => [],
            'query' => [
                'filters' => ['services' => ['checkout'], 'severities' => ['error', 'fatal']],
                'aggregation' => 'count',
            ],
            'condition' => AlertCondition::GreaterThan,
            'threshold' => 25,
            'window_seconds' => 300,
            'interval_seconds' => 60,
            'for_seconds' => 0,
            'keep_firing_for_seconds' => 0,
            'no_data_state' => NoDataState::Inactive,
            'severity' => AlertSeverity::Warning,
            'enabled' => true,
            'muted_until' => null,
            'next_evaluation_at' => now(),
            'last_evaluated_at' => null,
            'health' => AlertRuleHealth::Unknown,
            'last_error' => null,
            'created_by' => null,
        ];
    }

    /**
     * p95 latency of server spans, per service.
     */
    public function traces(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Checkout p95 latency',
            'source' => 'traces',
            'query' => [
                'filters' => ['kind' => 'Server'],
                'aggregation' => 'p95',
                'group_by' => 'service.name',
            ],
            'threshold' => 750,
            'for_seconds' => 300,
            'severity' => AlertSeverity::Critical,
        ]);
    }

    /**
     * Host CPU utilisation over 90%, per host, held for five minutes.
     */
    public function metrics(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Host CPU above 90%',
            'source' => 'metrics',
            'query' => [
                'metric' => 'system.cpu.utilization',
                'aggregation' => 'avg',
                'group_by' => 'host.name',
            ],
            'threshold' => 0.9,
            'for_seconds' => 300,
            'no_data_state' => NoDataState::Firing,
        ]);
    }

    /**
     * Rule switched off.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    /**
     * Rule muted for the next hour.
     */
    public function muted(): static
    {
        return $this->state(fn (): array => ['muted_until' => now()->addHour()]);
    }

    /**
     * Rule whose last evaluation failed.
     */
    public function failing(): static
    {
        return $this->state(fn (): array => [
            'health' => AlertRuleHealth::Err,
            'last_error' => 'ClickHouse timed out after 10s',
            'last_evaluated_at' => now(),
        ]);
    }

    /**
     * Rule belonging to the given team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (): array => ['team_id' => $team->id]);
    }
}
