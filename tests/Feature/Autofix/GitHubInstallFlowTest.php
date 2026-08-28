<?php

use App\Enums\TeamRole;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\GitHubInstallState;
use Illuminate\Support\Facades\Http;

/**
 * A team with one owner and a project to return to.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function githubInstallTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

/**
 * Answer GitHub's App-level installation lookup.
 */
function fakeGitHubInstallationAccount(string $login = 'acme', string $type = 'Organization'): void
{
    Http::fake([
        'api.github.com/app/installations/*' => Http::response([
            'id' => 4242,
            'account' => ['login' => $login, 'type' => $type],
        ]),
    ]);
}

beforeEach(function () {
    config()->set('autofix.enabled', true);
    config()->set('autofix.github.slug', 'bilis');
    config()->set('autofix.github.app_id', '12345');
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);

    config()->set('autofix.github.private_key', base64_encode((string) $pem));
});

test('connecting sends the user to the github install screen with signed state', function () {
    [$user, $team, $project] = githubInstallTeam();

    $response = $this->actingAs($user)
        ->get(route('github.installations.connect', [
            'current_team' => $team->slug,
            'project' => $project->slug,
        ]))
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://github.com/apps/bilis/installations/new?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(app(GitHubInstallState::class)->consume($query['state']))->toBe([
        'team' => $team->id,
        'user' => $user->id,
        'project' => 'checkout',
    ]);
});

test('the setup callback records the installation and lands on the project', function () {
    [$user, $team, $project] = githubInstallTeam();
    fakeGitHubInstallationAccount();

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    $this->actingAs($user)
        ->get(route('github.installations.setup', ['installation_id' => 4242, 'state' => $state]))
        ->assertRedirect(route('projects.show', [
            'current_team' => $team->slug,
            'project' => $project->slug,
            'repositories' => 1,
        ]));

    $this->assertDatabaseHas('github_installations', [
        'team_id' => $team->id,
        'installation_id' => 4242,
        'account_login' => 'acme',
        'account_type' => 'Organization',
    ]);
});

test('a tampered state is refused', function () {
    [$user, $team, $project] = githubInstallTeam();
    fakeGitHubInstallationAccount();

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    $this->actingAs($user)
        ->get(route('github.installations.setup', [
            'installation_id' => 4242,
            'state' => strrev($state),
        ]))
        ->assertRedirect();

    $this->assertDatabaseCount('github_installations', 0);
});

test('an expired state is refused', function () {
    [$user, $team, $project] = githubInstallTeam();
    fakeGitHubInstallationAccount();

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    $this->travel(GitHubInstallState::TTL_MINUTES + 1)->minutes();

    $this->actingAs($user)
        ->get(route('github.installations.setup', ['installation_id' => 4242, 'state' => $state]))
        ->assertRedirect();

    $this->assertDatabaseCount('github_installations', 0);
});

test('a state blob can only be spent once', function () {
    [$user, $team, $project] = githubInstallTeam();

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    expect(app(GitHubInstallState::class)->consume($state))->not->toBeNull()
        ->and(app(GitHubInstallState::class)->consume($state))->toBeNull();
});

test('an installation already claimed by another team is refused', function () {
    [$user, $team, $project] = githubInstallTeam();
    fakeGitHubInstallationAccount();

    $otherTeam = Team::factory()->create();
    GitHubInstallation::factory()->forTeam($otherTeam)->create(['installation_id' => 4242]);

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    $this->actingAs($user)
        ->get(route('github.installations.setup', ['installation_id' => 4242, 'state' => $state]))
        ->assertRedirect();

    $this->assertDatabaseHas('github_installations', [
        'installation_id' => 4242,
        'team_id' => $otherTeam->id,
    ]);
});

test('a state minted for a team the caller does not belong to is refused', function () {
    [, $team, $project] = githubInstallTeam();
    fakeGitHubInstallationAccount();

    $owner = $team->members()->first();
    $state = app(GitHubInstallState::class)->issue($team, $owner, $project->slug);

    $outsider = User::factory()->create();
    $outsiderTeam = Team::factory()->create();
    $outsiderTeam->members()->attach($outsider, ['role' => TeamRole::Owner->value]);

    $this->actingAs($outsider)
        ->get(route('github.installations.setup', ['installation_id' => 4242, 'state' => $state]))
        ->assertRedirect();

    $this->assertDatabaseCount('github_installations', 0);
});

test('a request for organisation approval records nothing yet', function () {
    [$user, $team, $project] = githubInstallTeam();
    Http::fake();

    $state = app(GitHubInstallState::class)->issue($team, $user, $project->slug);

    $this->actingAs($user)
        ->get(route('github.installations.setup', [
            'installation_id' => 4242,
            'setup_action' => 'request',
            'state' => $state,
        ]))
        ->assertRedirect();

    $this->assertDatabaseCount('github_installations', 0);
    Http::assertNothingSent();
});

test('a guest cannot reach the setup callback', function () {
    $this->get(route('github.installations.setup', ['installation_id' => 4242]))
        ->assertRedirect(route('login'));
});

test('a non member cannot start the connect flow for a team', function () {
    [, $team] = githubInstallTeam();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('github.installations.connect', ['current_team' => $team->slug]))
        ->assertForbidden();
});
