<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Seed a demo project with an API key for the first available user.
     */
    public function run(): void
    {
        $user = User::query()->oldest('id')->first() ?? User::factory()->create();

        $team = $user->currentTeam ?? app(CreateTeam::class)->handle($user, $user->name."'s Team", true);

        $project = Project::firstOrCreate(
            ['team_id' => $team->id, 'slug' => 'demo'],
            ['name' => 'Demo'],
        );

        if ($project->apiKeys()->exists()) {
            return;
        }

        $apiKey = ProjectApiKey::generate($project, 'Demo Key');

        $this->command->info("Demo project API key: {$apiKey->plainTextKey}");
    }
}
