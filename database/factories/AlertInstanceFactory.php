<?php

namespace Database\Factories;

use App\Enums\AlertState;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Services\Alerting\Labels;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertInstance>
 */
class AlertInstanceFactory extends Factory
{
    /**
     * Define the model's default state: an inactive series for one service.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A realistic name, made unique by a suffix: `unique()` over the
        // eight names alone would run out on the ninth instance.
        $labels = ['service.name' => fake()->randomElement([
            'checkout', 'payments', 'auth', 'search', 'worker', 'gateway', 'inventory', 'emails',
        ]).'-'.fake()->unique()->numberBetween(1, 999_999)];

        return [
            'alert_rule_id' => AlertRule::factory(),
            'labels' => $labels,
            'labels_hash' => Labels::hash($labels),
            'state' => AlertState::Inactive,
            'value' => 3.0,
            'active_at' => null,
            'fired_at' => null,
            'keep_firing_since' => null,
            'last_seen_with_data_at' => now(),
            'resolved_at' => null,
        ];
    }

    /**
     * The given labels, with their hash.
     *
     * @param  array<string, string>  $labels
     */
    public function withLabels(array $labels): static
    {
        return $this->state(fn (): array => [
            'labels' => $labels,
            'labels_hash' => Labels::hash($labels),
        ]);
    }

    /**
     * Over the threshold, waiting out `for`.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'state' => AlertState::Pending,
            'value' => 41.0,
            'active_at' => now()->subMinute(),
        ]);
    }

    /**
     * Firing for the last ten minutes.
     */
    public function firing(): static
    {
        return $this->state(fn (): array => [
            'state' => AlertState::Firing,
            'value' => 87.0,
            'active_at' => now()->subMinutes(15),
            'fired_at' => now()->subMinutes(10),
        ]);
    }
}
