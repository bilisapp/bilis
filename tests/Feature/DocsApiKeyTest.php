<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/**
 * Build a team with one owner, and make it the user's current team.
 *
 * @return array{0: User, 1: Team}
 */
function docsTeam(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    // The factory gives every user a personal team; the docs panel follows
    // whichever team is current, so make it this one.
    $user->switchTeam($team);

    return [$user->fresh(), $team];
}

it('invites a logged-out reader to sign in before issuing a key', function () {
    get(route('docs.show', ['section' => 'getting-started', 'page' => 'quickstart']))
        ->assertOk()
        ->assertSee('Fill this page in with a real API key')
        ->assertSee(route('register'), false)
        ->assertDontSee('Create API key');
});

it('offers the key form to a signed-in reader, with the team projects', function () {
    [$user, $team] = docsTeam();
    Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    Project::factory()->create(['name' => 'Another Team Project']);

    $this->actingAs($user)
        ->get(route('docs.show', ['section' => 'getting-started', 'page' => 'quickstart']))
        ->assertOk()
        ->assertSee('Create API key')
        ->assertSee('Checkout')
        ->assertDontSee('Another Team Project');
});

it('leaves the panel off a page that has no key placeholder', function () {
    get(route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']))
        ->assertOk()
        ->assertDontSee('Fill this page in with a real API key');
});

it('creates a project and issues a key from the docs', function () {
    [$user, $team] = docsTeam();

    $response = $this->actingAs($user)
        ->postJson(route('docs.api-key'), ['name' => 'my-app'])
        ->assertCreated();

    $project = $team->projects()->sole();

    expect($project->name)->toBe('my-app')
        ->and($project->apiKeys()->sole()->name)->toBe('Docs quickstart');

    $response->assertJsonPath('project.slug', 'my-app')
        ->assertJsonPath('project.created', true)
        ->assertJsonPath('endpoint', rtrim(url('/'), '/'));

    // The plaintext key comes back once, and only its hash is stored.
    $key = $response->json('key');

    expect($key)->toStartWith('bilis_')
        ->and(ProjectApiKey::findByPlainKey($key)?->project_id)->toBe($project->id);
});

it('issues a key into an existing project of the team', function () {
    [$user, $team] = docsTeam();
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    $this->actingAs($user)
        ->postJson(route('docs.api-key'), ['project' => 'checkout'])
        ->assertCreated()
        ->assertJsonPath('project.slug', 'checkout')
        ->assertJsonPath('project.created', false);

    expect($team->projects()->count())->toBe(1)
        ->and($project->apiKeys()->count())->toBe(1);
});

it('never issues a key into another team\'s project', function () {
    [$user] = docsTeam();
    $other = Project::factory()->create(['slug' => 'not-yours']);

    $this->actingAs($user)
        ->postJson(route('docs.api-key'), ['project' => 'not-yours'])
        ->assertNotFound();

    expect($other->apiKeys()->count())->toBe(0);
});

it('requires a name when no project is chosen', function () {
    [$user] = docsTeam();

    $this->actingAs($user)
        ->postJson(route('docs.api-key'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('does not issue keys to a logged-out visitor', function () {
    postJson(route('docs.api-key'), ['name' => 'my-app'])->assertUnauthorized();

    expect(ProjectApiKey::count())->toBe(0);
});
