<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRepository>
 */
class ProjectRepositoryFactory extends Factory
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
            'github_installation_id' => GitHubInstallation::factory(),
            'repo_full_name' => fake()->unique()->userName().'/'.fake()->slug(2),
            'default_branch' => 'main',
            'autofix_enabled' => false,
            'test_cmd' => null,
            'max_concurrent' => 1,
            'daily_budget' => 5,
        ];
    }

    /**
     * Indicate that the repository belongs to the given project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }

    /**
     * Indicate that the repository is reached through the given installation.
     */
    public function forInstallation(GitHubInstallation $installation): static
    {
        return $this->state(fn (array $attributes) => [
            'github_installation_id' => $installation->id,
        ]);
    }

    /**
     * Indicate that the project has opted into autofix for this repository.
     */
    public function autofixEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'autofix_enabled' => true,
        ]);
    }

    /**
     * Indicate that the agent should run the given test command.
     */
    public function withTestCommand(string $command = 'php artisan test --compact'): static
    {
        return $this->state(fn (array $attributes) => [
            'test_cmd' => $command,
        ]);
    }
}
