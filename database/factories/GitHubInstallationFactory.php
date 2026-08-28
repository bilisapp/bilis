<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubInstallation>
 */
class GitHubInstallationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'installation_id' => fake()->unique()->numberBetween(1_000_000, 99_999_999),
            'account_login' => fake()->unique()->userName(),
            'account_type' => 'Organization',
        ];
    }

    /**
     * Indicate that the installation belongs to the given team.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes) => [
            'team_id' => $team->id,
        ]);
    }

    /**
     * Indicate that the installation sits on a personal account.
     */
    public function personalAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => 'User',
        ]);
    }
}
