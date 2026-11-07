<?php

namespace Database\Factories;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Issue>
 */
class IssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'status' => IssueStatus::Unresolved,
            'resolved_at' => null,
        ];
    }

    /**
     * Mark the issue resolved at the given moment (now by default).
     */
    public function resolved(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => IssueStatus::Resolved,
            'resolved_at' => $at ?? now(),
        ]);
    }

    /**
     * Mark the issue ignored.
     */
    public function ignored(): static
    {
        return $this->state(fn (): array => ['status' => IssueStatus::Ignored]);
    }
}
