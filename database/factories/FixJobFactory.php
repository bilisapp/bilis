<?php

namespace Database\Factories;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Models\FixJob;
use App\Models\Project;
use App\Models\ProjectRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixJob>
 */
class FixJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_repository_id' => ProjectRepository::factory(),
            'project_id' => fn (array $attributes): int => ProjectRepository::query()
                ->whereKey($attributes['project_repository_id'])
                ->value('project_id'),
            'type' => FixJobType::Error,
            'fingerprint' => hash('sha256', fake()->unique()->sentence()),
            'error_context' => [
                'service_name' => 'checkout',
                'exception' => 'RuntimeException',
                'message' => 'Undefined array key "total"',
                'count' => 12,
            ],
            'instructions' => null,
            'base_sha' => fake()->sha1(),
            'status' => FixJobStatus::Pending,
            'diff' => null,
            'report' => null,
            'events' => null,
            'verification' => null,
            'pr_number' => null,
            'pr_url' => null,
            'failure_reason' => null,
            'dispatched_at' => null,
            'completed_at' => null,
            'verified_at' => null,
        ];
    }

    /**
     * Indicate that the job was spawned by a person rather than by the scan.
     *
     * A custom job has no fingerprint and no error context — the request is
     * the whole of what it knows.
     */
    public function custom(?string $instructions = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => FixJobType::Custom,
            'fingerprint' => null,
            'error_context' => null,
            'instructions' => $instructions ?? 'Upgrade the guzzlehttp/guzzle dependency to the latest 7.x release and make sure the test suite still passes.',
        ]);
    }

    /**
     * Indicate that the job belongs to the given project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
            'project_repository_id' => ProjectRepository::factory()->forProject($project),
        ]);
    }

    /**
     * Indicate that the job runs against the given repository.
     */
    public function forRepository(ProjectRepository $repository): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $repository->project_id,
            'project_repository_id' => $repository->id,
        ]);
    }

    /**
     * Indicate that the job has been handed to Ayos.
     */
    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Dispatched,
            'dispatched_at' => now(),
        ]);
    }

    /**
     * Indicate that the agent is currently working on the job.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Running,
            'dispatched_at' => now()->subMinutes(2),
        ]);
    }

    /**
     * Indicate that the job produced a pull request.
     */
    public function prOpened(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::PrOpened,
            'diff' => "--- a/app/Foo.php\n+++ b/app/Foo.php\n",
            'report' => ['summary' => 'Guarded the missing array key.'],
            'pr_number' => fake()->numberBetween(1, 500),
            'pr_url' => 'https://github.com/acme/app/pull/'.fake()->numberBetween(1, 500),
            'dispatched_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the pull request was merged.
     */
    public function merged(): static
    {
        return $this->prOpened()->state(fn (array $attributes) => [
            'status' => FixJobStatus::Merged,
        ]);
    }

    /**
     * Indicate that the merged fix was checked and the error stopped recurring.
     */
    public function verified(): static
    {
        return $this->merged()->state(fn (array $attributes) => [
            'verified_at' => now(),
            'verification' => [
                'outcome' => 'verified',
                'checked_at' => now()->utc()->toIso8601ZuluString(),
                'window' => [
                    'from' => now()->subHours(3)->utc()->toIso8601ZuluString(),
                    'to' => now()->utc()->toIso8601ZuluString(),
                ],
                'occurrences' => 0,
            ],
        ]);
    }

    /**
     * Indicate that the diff was refused before any GitHub write.
     */
    public function rejected(string $reason = 'Diff touches .github/**'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Rejected,
            'failure_reason' => $reason,
            'dispatched_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the job failed.
     */
    public function failed(string $reason = 'Ayos returned 500'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Failed,
            'failure_reason' => $reason,
            'dispatched_at' => now()->subMinutes(10),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the job ran past its wall clock budget.
     */
    public function timedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Timeout,
            'failure_reason' => 'Timed out after 900s',
            'dispatched_at' => now()->subMinutes(20),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the job was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FixJobStatus::Cancelled,
            'dispatched_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
