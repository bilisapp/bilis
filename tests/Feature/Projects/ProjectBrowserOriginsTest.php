<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

/**
 * Build a team with one owner and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function originsProject(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

test('origins are saved one per line and normalized', function () {
    [$user, $team, $project] = originsProject();

    $this->actingAs($user)
        ->patch(route('projects.browser-origins.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            // Trailing slashes, a path, mixed case and a blank line: all of it
            // is what someone actually pastes out of an address bar.
            'origins' => "https://Shop.Example.com/\n\nhttps://app.example.com:8443/checkout\n",
        ])
        ->assertRedirect();

    expect($project->fresh()->allowed_origins)
        ->toBe(['https://shop.example.com', 'https://app.example.com:8443']);
});

test('duplicates and unusable entries are dropped', function () {
    [$user, $team, $project] = originsProject();

    $this->actingAs($user)
        ->patch(route('projects.browser-origins.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'origins' => "https://shop.example.com, https://shop.example.com\nnot a url at all\nftp://files.example.com",
        ])
        ->assertRedirect();

    expect($project->fresh()->allowed_origins)->toBe(['https://shop.example.com']);
});

test('an emptied list closes the door again', function () {
    [$user, $team, $project] = originsProject();
    $project->update(['allowed_origins' => ['https://shop.example.com']]);

    $this->actingAs($user)
        ->patch(route('projects.browser-origins.update', ['current_team' => $team->slug, 'project' => $project->slug]), [
            'origins' => '',
        ])
        ->assertRedirect();

    expect($project->fresh()->allowed_origins)->toBe([])
        ->and($project->fresh()->allowsOrigin('https://shop.example.com'))->toBeFalse();
});

test('another team cannot touch the list', function () {
    [, , $project] = originsProject();
    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider, ['role' => TeamRole::Owner->value]);

    $this->actingAs($outsider)
        ->patch(route('projects.browser-origins.update', ['current_team' => $otherTeam->slug, 'project' => $project->slug]), [
            'origins' => 'https://evil.example.com',
        ])
        ->assertNotFound();

    expect($project->fresh()->allowed_origins)->toBeNull();
});

test('the project page carries the list it should render', function () {
    [$user, $team, $project] = originsProject();
    $project->update(['allowed_origins' => ['https://shop.example.com']]);

    $this->actingAs($user)
        ->get(route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('project.allowedOrigins', ['https://shop.example.com']));
});
