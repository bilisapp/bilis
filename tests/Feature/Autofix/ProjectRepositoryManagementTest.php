<?php

use App\Enums\TeamRole;
use App\Models\FixJob;
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

test('the project page carries the repositories and the installations', function () {
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
            ->has('repositories', 1)
            ->where('repositories.0.repoFullName', 'acme/checkout')
            ->where('repositories.0.autofixEnabled', true)
            ->where('repositories.0.accountLogin', 'acme')
            ->where('repositories.0.isCatchAll', true)
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
            'repository' => $repository->id,
        ]), [
            'autofix_enabled' => true,
            'test_cmd' => 'php artisan test --compact',
            'max_concurrent' => 2,
            'daily_budget' => 12,
            'services' => ['*'],
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

    $repository = ProjectRepository::factory()->forProject($project)->forInstallation($installation)->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => ['*'],
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

    $job = FixJob::factory()->forRepository($repository)->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->delete(route('projects.repository.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]))
        ->assertRedirect();

    // The project reads as disconnected, autofix is off, and every job raised
    // against the repository — the history and the cooldown state both — is
    // still there, still able to name the repository it ran in.
    expect($project->fresh()->repositories()->count())->toBe(0)
        ->and($repository->fresh()->autofix_enabled)->toBeFalse()
        ->and($job->fresh())->not->toBeNull()
        ->and($job->fresh()->repository->repo_full_name)->toBe($repository->repo_full_name);

    $this->assertDatabaseHas('fix_jobs', ['id' => $job->id]);
    $this->assertSoftDeleted('project_repositories', ['id' => $repository->id]);
});

test('reconnecting the same repository restores it and the jobs under it', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    $job = FixJob::factory()->forRepository($repository)->create();

    $repository->delete();

    fakeGrantedGitHubRepositories([
        ['full_name' => 'acme/checkout', 'default_branch' => 'main', 'private' => true],
    ]);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->post(route('projects.repository.store', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'installation_id' => $installation->installation_id,
            'repo_full_name' => 'acme/checkout',
        ])
        ->assertRedirect();

    expect($project->fresh()->repositories()->first()?->id)->toBe($repository->id)
        ->and($job->fresh()->project_repository_id)->toBe($repository->id);
});

/*
 * A project ships several services and they need not share a codebase, so a
 * second repository is added alongside the first rather than replacing it.
 * This used to retire the old row, which was the one-repository product rule
 * rather than anything the schema required.
 */
test('connecting a second repository adds it alongside the first', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    $job = FixJob::factory()->forRepository($repository)->create();

    fakeGrantedGitHubRepositories([
        ['full_name' => 'acme/billing', 'default_branch' => 'trunk', 'private' => true],
    ]);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->post(route('projects.repository.store', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]), [
            'installation_id' => $installation->installation_id,
            'repo_full_name' => 'acme/billing',
        ])
        ->assertRedirect();

    $repositories = $project->fresh()->repositories()->orderBy('id')->get();

    expect($repositories)->toHaveCount(2)
        ->and($repositories->pluck('repo_full_name')->all())->toBe(['acme/checkout', 'acme/billing'])
        // The first repository keeps the catch-all; the second claims nothing
        // until somebody says which services it is responsible for.
        ->and($repositories[0]->isCatchAll())->toBeTrue()
        ->and($repositories[1]->services)->toHaveCount(0)
        ->and($job->fresh()->project_repository_id)->toBe($repository->id);

    $this->assertNotSoftDeleted('project_repositories', ['id' => $repository->id]);
});

test('a member of another team cannot manage a project repository', function () {
    [, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()->forProject($project)->forInstallation($installation)->create();

    $outsider = User::factory()->create();
    Http::fake();

    $this->actingAs($outsider)
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]), ['autofix_enabled' => true, 'max_concurrent' => 1, 'daily_budget' => 5, 'services' => ['*']])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('projects.repository.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->getJson(route('projects.repository.available', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertForbidden();
});

/* --------------------------------------------------------- service claims */

/*
 * One service, one repository. Two claims on `checkout` would raise a job on
 * each for every checkout error, which is the thing the mapping exists to
 * prevent — so it is refused at the point of saving rather than discovered
 * from a duplicated pull request.
 */
test('a service already claimed by a sibling repository is refused', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['checkout'])
        ->create(['repo_full_name' => 'acme/checkout']);

    $billing = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['billing'])
        ->create(['repo_full_name' => 'acme/billing']);

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $billing->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => ['billing', 'checkout'],
        ])
        ->assertSessionHasErrors('services');

    expect($billing->fresh()->services->pluck('service_name')->all())->toBe(['billing']);
});

test('the same service may be re-saved on the repository that already holds it', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['checkout'])
        ->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => ['checkout', 'checkout-worker'],
        ])
        ->assertSessionHasNoErrors();

    expect($repository->fresh()->services->pluck('service_name')->sort()->values()->all())
        ->toBe(['checkout', 'checkout-worker']);
});

/*
 * Autofix that is switched on and silently scans nothing is worse than autofix
 * that refuses to switch on.
 */
test('autofix cannot be enabled on a repository that claims no service', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create();

    $this->actingAs($user)
        ->from(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => [],
        ])
        ->assertSessionHasErrors('services');

    expect($repository->fresh()->autofix_enabled)->toBeFalse();
});

test('claims are replaced wholesale rather than merged', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['checkout', 'checkout-worker'])
        ->create();

    $this->actingAs($user)
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => ['checkout'],
        ])
        ->assertSessionHasNoErrors();

    expect($repository->fresh()->services->pluck('service_name')->all())->toBe(['checkout']);
});

/*
 * A soft-deleted repository holding `checkout` would block another from ever
 * claiming it, with nothing on screen to explain why.
 */
test('disconnecting a repository releases the services it claimed', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['checkout'])
        ->create();

    $this->actingAs($user)
        ->delete(route('projects.repository.destroy', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $repository->id,
        ]))
        ->assertRedirect();

    $this->assertDatabaseMissing('project_repository_services', [
        'project_repository_id' => $repository->id,
    ]);

    // And the name is free again.
    $replacement = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->forServices(['checkout'])
        ->create(['repo_full_name' => 'acme/checkout-v2']);

    expect($replacement->fresh()->services->pluck('service_name')->all())->toBe(['checkout']);
});

test('a repository id from another project is not reachable through this one', function () {
    [$user, $team, $project, $installation] = projectRepositoryTeam();

    $otherProject = Project::factory()->forTeam($team)->create(['slug' => 'billing']);
    $theirs = ProjectRepository::factory()
        ->forProject($otherProject)
        ->forInstallation($installation)
        ->create();

    $this->actingAs($user)
        ->patch(route('projects.repository.update', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repository' => $theirs->id,
        ]), [
            'autofix_enabled' => true,
            'max_concurrent' => 1,
            'daily_budget' => 5,
            'services' => ['*'],
        ])
        ->assertNotFound();
});
