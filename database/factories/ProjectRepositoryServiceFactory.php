<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\ProjectRepositoryService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRepositoryService>
 */
class ProjectRepositoryServiceFactory extends Factory
{
    protected $model = ProjectRepositoryService::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_repository_id' => ProjectRepository::factory(),
            'project_id' => Project::factory(),
            'service_name' => fake()->unique()->slug(2),
        ];
    }

    /**
     * Claim a named service for a repository.
     */
    public function forRepository(ProjectRepository $repository, string $serviceName): static
    {
        return $this->state(fn (): array => [
            'project_repository_id' => $repository->getKey(),
            'project_id' => $repository->project_id,
            'service_name' => $serviceName,
        ]);
    }

    /**
     * Every service no other repository in the project has named.
     */
    public function catchAll(): static
    {
        return $this->state(fn (): array => [
            'service_name' => ProjectRepositoryService::CATCH_ALL,
        ]);
    }
}
