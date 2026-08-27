<?php

use App\Enums\TeamRole;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team with one owner, a project and a GitHub App installation.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: GitHubInstallation}
 */
function projectRepositoryTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create([
        'installation_id' => 4242,
        'account_login' => 'acme',
    ]);

    return [$user, $team, $project, $installation];
}

/**
 * Fake the token exchange and the installation's repository listing.
 *
 * @param  list<array<string, mixed>>  $repositories
 */
function fakeGrantedGitHubRepositories(array $repositories): void
{
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_metadata']),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => $repositories]),
    ]);
}

beforeEach(function () {
    config()->set('autofix.enabled', true);
    config()->set('autofix.github.slug', 'bilis');
    config()->set('autofix.github.app_id', '12345');

    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);

    config()->set('autofix.github.private_key', base64_encode((string) $pem));
});

test('the project page carries the repository and the installations', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->where('repository.repoFullName', 'acme/checkout')
            ->where('repository.autofixEnabled', true)
            ->where('repository.accountLogin', 'acme')
            ->has('installations', 1)
            ->where('installations.0.accountLogin', 'acme')
            ->where('autofix.enabled', true)
            ->where('autofix.githubConfigured', true),
        );
});

test('the available repositories come from the installation listing', function () {
    [$user, $team, $project] = projectRepositoryTeam();

    fakeGrantedGitHubRepositories([
        ['full_name' => 'acme/web', 'default_branch' => 'trunk', 'private' => true],
        ['full_name' => 'acme/checkout', 'default_branch' => 'main', 'private' => false],
    ]);

    $this->actingAs($user)
        ->getJson(route('projects.repository.available', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertOk()
        ->assertJsonPath('unavailable', false)
        ->assertJsonPath('installations.0.repositories.0.full_name', 'acme/checkout')
        ->assertJsonPath('installations.0.repositories.1.default_branch', 'trunk');
});

test('a github outage degrades the picker instead of the page', function () {
    [$user, $team, $project] = projectRepositoryTeam();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response('nope', 500),
    ]);

    $this->actingAs($user)
        ->getJson(route('projects.repository.available', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertOk()
        ->assertJsonPath('unavailable', true);
});

test('a repository can be connected to a project', function () {
    [$user, $team, $project] = projectRepositoryTeam();

    fakeGrantedGitHubRepositories([
        ['full_name' => 'acme/checkout', 'default_branch' => 'main', 'private' => true],
    ]);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->post(route('projects.repository.store', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'installation_id' => 4242,
            'repo_full_name' => 'acme/checkout',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('project_repositories', [
        'project_id' => $project->id,
        'repo_full_name' => 'acme/checkout',
        'default_branch' => 'main',
        'autofix_enabled' => false,
    ]);
});

test('a repository github never granted cannot be connected', function () {
    [$user, $team, $project] = projectRepositoryTeam();

    fakeGrantedGitHubRepositories([
        ['full_name' => 'acme/checkout', 'default_branch' => 'main', 'private' => true],
    ]);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->post(route('projects.repository.store', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'installation_id' => 4242,
            'repo_full_name' => 'someone-else/secrets',
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('project_repositories', 0);
});

test('an installation belonging to another team cannot be used', function () {
    [$user, $team, $project] = projectRepositoryTeam();

    $otherInstallation = GitHubInstallation::factory()
        ->forTeam(Team::factory()->create())
        ->create(['installation_id' => 9999]);

    Http::fake();

    $this->actingAs($user)
        ->post(route('projects.repository.store', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'installation_id' => $otherInstallation->installation_id,
            'repo_full_name' => 'acme/checkout',
        ])
        ->assertNotFound();

    Http::assertNothingSent();
});

test('the autofix settings of a connected repository can be updated', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create(['max_concurrent' => 1, 'daily_budget' => 5]);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'autofix_enabled' => true,
            'test_cmd' => 'php artisan test --compact',
            'max_concurrent' => 2,
            'daily_budget' => 12,
        ])
        ->assertRedirect();

    expect($repository->fresh())
        ->autofix_enabled->toBeTrue()
        ->test_cmd->toBe('php artisan test --compact')
        ->max_concurrent->toBe(2)
        ->daily_budget->toBe(12);
});

test('the budgets are bounded', function (array $payload) {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    ProjectRepository::factory()->forProject($project)->forInstallation($installation)->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            ...$payload,
        ])
        ->assertSessionHasErrors(array_keys($payload));
})->with([
    'no concurrency' => [['max_concurrent' => 0]],
    'runaway concurrency' => [['max_concurrent' => 99]],
    'runaway budget' => [['daily_budget' => 5000]],
]);

test('a repository can be disconnected without losing the job history', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->delete(route('projects.repository.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertRedirect();

    $this->assertDatabaseMissing('project_repositories', ['id' => $repository->id]);
});

test('a member of another team cannot manage a project repository', function () {
    [, $team, $project, $installation] = projectRepositoryTeam();

    ProjectRepository::factory()->forProject($project)->forInstallation($installation)->create();

    $outsider = User::factory()->create();
    Http::fake();

    $this->actingAs($outsider)
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), ['autofix_enabled' => true, 'max_concurrent' => 1, 'daily_budget' => 5])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('projects.repository.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->getJson(route('projects.repository.available', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertForbidden();
});
