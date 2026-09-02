<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a team with one owner.
 *
 * @return array{0: User, 1: Team}
 */
function projectTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return [$user, $team];
}

test('the projects index lists the team projects with their key counts', function () {
    [$user, $team] = projectTeam();

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    ProjectApiKey::factory()->forProject($project)->count(2)->create();
    Project::factory()->create(['name' => 'Other Team Project']);

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Index')
            ->has('projects', 1)
            ->where('projects.0.name', 'Checkout')
            ->where('projects.0.slug', 'checkout')
            ->where('projects.0.apiKeysCount', 2),
        );
});

test('the projects index states how much of the Free allowance is spent', function () {
    [$user, $team] = projectTeam();

    Project::factory()->forTeam($team)->count(3)->create();

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Index')
            ->where('planProjects.used', 3)
            ->where('planProjects.limit', (int) config('plans.free.projects_per_team')),
        );
});

test('a team at its project allowance can still create a project', function () {
    // The allowance is soft: it is reported, never enforced. A team that is
    // already over must be able to create the next one.
    [$user, $team] = projectTeam();

    config(['plans.free.projects_per_team' => 1]);

    Project::factory()->forTeam($team)->create();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Fourth'])
        ->assertRedirect();

    expect($team->projects()->count())->toBe(2);
});

test('guests are redirected away from the projects index', function () {
    $team = Team::factory()->create();

    $this->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('non members cannot see the projects of a team', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.index', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('a project can be created for the current team', function () {
    [$user, $team] = projectTeam();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Checkout API'])
        ->assertRedirect(route('projects.show', ['current_team' => $team->slug, 'project' => 'checkout-api']));

    $this->assertDatabaseHas('projects', [
        'team_id' => $team->id,
        'name' => 'Checkout API',
        'slug' => 'checkout-api',
    ]);
});

test('project slugs stay unique within a team', function () {
    [$user, $team] = projectTeam();

    Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), ['name' => 'Checkout'])
        ->assertRedirect();

    $this->assertDatabaseHas('projects', [
        'team_id' => $team->id,
        'slug' => 'checkout-1',
    ]);
});

test('creating a project requires a name', function () {
    [$user, $team] = projectTeam();

    $this->actingAs($user)
        ->post(route('projects.store', ['current_team' => $team->slug]), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('the project detail page renders its api keys', function () {
    [$user, $team] = projectTeam();

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $used = ProjectApiKey::factory()->forProject($project)->used()->create(['name' => 'Collector']);
    ProjectApiKey::factory()->forProject($project)->create(['name' => 'Laptop']);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->where('project.name', 'Checkout')
            ->where('teamSlug', $team->slug)
            ->has('apiKeys', 2)
            ->where('apiKeys.1.name', 'Collector')
            ->where('apiKeys.1.keyPrefix', $used->key_prefix)
            ->where('apiKeys.0.lastUsedAt', null),
        );
});

test('a project slug only resolves within the current team', function () {
    [$user, $team] = projectTeam();

    $otherProject = Project::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $otherProject->slug]))
        ->assertNotFound();
});

test('a shared slug resolves to the current team project', function () {
    [$user, $team] = projectTeam();

    $ownProject = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    Project::factory()->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => 'checkout']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('project.id', $ownProject->id)->etc());
});

test('a project can be renamed', function () {
    [$user, $team] = projectTeam();

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $this->actingAs($user)
        ->patch(route('projects.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'name' => 'Checkout API',
        ])
        ->assertRedirect(route('projects.show', ['current_team' => $team->slug, 'project' => 'checkout']));

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'name' => 'Checkout API',
        'slug' => 'checkout',
    ]);
});

test('a project can be deleted with its api keys', function () {
    [$user, $team] = projectTeam();

    $project = Project::factory()->forTeam($team)->create();
    $apiKey = ProjectApiKey::factory()->forProject($project)->create();

    $this->actingAs($user)
        ->delete(route('projects.destroy', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertRedirect(route('projects.index', ['current_team' => $team->slug]));

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('project_api_keys', ['id' => $apiKey->id]);
});
