<?php

namespace Database\Factories;

use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\ProjectRepositoryService;
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
     * Give the first repository of a project the catch-all service claim.
     *
     * The same thing `ProjectRepositoryController::connect()` does: one
     * repository on a project scans everything, and only a second one forces
     * anybody to say which service is whose. Without this every factory-built
     * repository would claim nothing and the scan would correctly skip it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProjectRepository $repository): void {
            $claimed = ProjectRepositoryService::query()
                ->where('project_id', $repository->project_id)
                ->exists();

            if ($claimed) {
                return;
            }

            $repository->services()->create([
                'project_id' => $repository->project_id,
                'service_name' => ProjectRepositoryService::CATCH_ALL,
            ]);
        });
    }

    /**
     * Claim named services for this repository instead of the catch-all.
     *
     * @param  list<string>  $services
     */
    public function forServices(array $services): static
    {
        return $this->afterCreating(function (ProjectRepository $repository) use ($services): void {
            $repository->services()->delete();

            foreach ($services as $service) {
                $repository->services()->create([
                    'project_id' => $repository->project_id,
                    'service_name' => $service,
                ]);
            }

            $repository->unsetRelation('services');
        });
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
