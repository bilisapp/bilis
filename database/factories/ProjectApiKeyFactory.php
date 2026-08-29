<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectApiKey>
 */
class ProjectApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainTextKey = ProjectApiKey::KEY_PREFIX.Str::random(ProjectApiKey::RANDOM_LENGTH);

        return [
            'project_id' => Project::factory(),
            'name' => Str::title(fake()->word().' Key'),
            'key_prefix' => Str::substr($plainTextKey, 0, ProjectApiKey::DISPLAY_PREFIX_LENGTH),
            'key_hash' => ProjectApiKey::hashKey($plainTextKey),
            'public_key' => ProjectApiKey::PUBLIC_KEY_PREFIX.Str::random(ProjectApiKey::RANDOM_LENGTH),
            'last_used_at' => null,
        ];
    }

    /**
     * Indicate that the API key belongs to the given project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
        ]);
    }

    /**
     * Indicate that the API key hashes the given plaintext key.
     */
    public function withPlainKey(string $plainTextKey): static
    {
        return $this->state(fn (array $attributes) => [
            'key_prefix' => Str::substr($plainTextKey, 0, ProjectApiKey::DISPLAY_PREFIX_LENGTH),
            'key_hash' => ProjectApiKey::hashKey($plainTextKey),
        ]);
    }

    /**
     * Indicate that the API key carries the given public key.
     */
    public function withPublicKey(string $publicKey): static
    {
        return $this->state(fn (array $attributes) => [
            'public_key' => $publicKey,
        ]);
    }

    /**
     * Indicate that the API key has recently been used.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_used_at' => now(),
        ]);
    }
}
