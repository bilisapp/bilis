<?php

namespace Database\Factories;

use App\Enums\ContactTopic;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'topic' => ContactTopic::General,
            'message' => fake()->paragraph(),
            'user_id' => null,
            'team_id' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0',
        ];
    }

    /**
     * A team asking about more than the Free plan.
     */
    public function upgrade(): static
    {
        return $this->state(fn (array $attributes): array => [
            'topic' => ContactTopic::Upgrade,
        ]);
    }
}
