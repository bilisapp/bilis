<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;
use App\Models\User;
use Inertia\Support\SessionKey;

/**
 * Build a team with one owner and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function apiKeyProject(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

test('creating an api key flashes the plaintext key once and stores only its hash', function () {
    [$user, $team, $project] = apiKeyProject();

    $response = $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->post(route('projects.api-keys.store', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => 'Production collector',
        ]);

    $response->assertRedirect()->assertInertiaFlash('newApiKey.name', 'Production collector');

    $apiKey = ProjectApiKey::query()->where('project_id', $project->id)->firstOrFail();
    $plainTextKey = session(SessionKey::FLASH_DATA)['newApiKey']['key'] ?? null;

    expect($plainTextKey)->toBeString()
        ->and($plainTextKey)->toStartWith(ProjectApiKey::KEY_PREFIX)
        ->and($apiKey->key_hash)->toBe(ProjectApiKey::hashKey($plainTextKey))
        ->and($apiKey->key_prefix)->toBe(substr($plainTextKey, 0, ProjectApiKey::DISPLAY_PREFIX_LENGTH))
        ->and($apiKey->getAttributes())->not->toHaveKey('plainTextKey');

    $this->assertDatabaseMissing('project_api_keys', ['key_hash' => $plainTextKey]);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertiaFlashMissing('newApiKey');
});

test('creating an api key requires a name', function () {
    [$user, $team, $project] = apiKeyProject();

    $this->actingAs($user)
        ->post(route('projects.api-keys.store', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => '',
        ])
        ->assertSessionHasErrors('name');

    expect(ProjectApiKey::query()->count())->toBe(0);
});

test('an api key can be revoked', function () {
    [$user, $team, $project] = apiKeyProject();

    $apiKey = ProjectApiKey::factory()->forProject($project)->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->delete(route('projects.api-keys.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'apiKey' => $apiKey->id,
        ]))
        ->assertRedirect(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]));

    $this->assertDatabaseMissing('project_api_keys', ['id' => $apiKey->id]);
});

test('an api key of another project cannot be revoked', function () {
    [$user, $team, $project] = apiKeyProject();

    $otherApiKey = ProjectApiKey::factory()->create();

    $this->actingAs($user)
        ->delete(route('projects.api-keys.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'apiKey' => $otherApiKey->id,
        ]))
        ->assertNotFound();

    $this->assertDatabaseHas('project_api_keys', ['id' => $otherApiKey->id]);
});

test('non members cannot create api keys for a project', function () {
    [, $team, $project] = apiKeyProject();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('projects.api-keys.store', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => 'Sneaky',
        ])
        ->assertForbidden();

    expect(ProjectApiKey::query()->count())->toBe(0);
});

test('guests cannot create api keys', function () {
    [, $team, $project] = apiKeyProject();

    $this->post(route('projects.api-keys.store', ['current_team' => $team->slug, 'project' => $project->slug]), [
        'name' => 'Sneaky',
    ])->assertRedirect(route('login'));
});
