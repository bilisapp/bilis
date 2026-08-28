<?php

namespace Database\Factories;

use App\Enums\LlmProvider;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamLlmCredential>
 */
class TeamLlmCredentialFactory extends Factory
{
    protected $model = TeamLlmCredential::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'sk-ant-'.fake()->regexify('[a-zA-Z0-9]{32}');

        return [
            'team_id' => Team::factory(),
            'provider' => LlmProvider::Anthropic,
            'label' => fake()->words(2, true),
            'api_key' => $key,
            'hint' => mb_substr($key, -TeamLlmCredential::HINT_LENGTH),
            'is_default' => false,
            'last_used_at' => null,
        ];
    }

    /**
     * The credential a team's jobs run on unless one is picked by hand.
     */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * A credential for a specific provider.
     */
    public function provider(LlmProvider $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }
}
