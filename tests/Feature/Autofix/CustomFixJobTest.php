<?php

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Enums\TeamRole;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\FixJobBudget;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team whose one project ships from a repository that opted into autofix.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: ProjectRepository}
 */
function customJobTeam(array $repositoryAttributes = []): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main', ...$repositoryAttributes]);

    return [$user, $team, $project, $repository];
}

/**
 * The URL a custom job is spawned at.
 */
function customJobUrl(Team $team): string
{
    return route('autofix.store', ['current_team' => $team->slug]);
}

beforeEach(function () {
    Queue::fake();
});

test('a team member can spawn a custom job against a connected repository', function () {
    [$user, $team, $project, $repository] = customJobTeam();

    $instructions = 'Upgrade guzzlehttp/guzzle to the latest 7.x release and leave the suite passing.';

    $this->actingAs($user)
        ->post(customJobUrl($team), ['project' => 'checkout', 'instructions' => $instructions])
        ->assertRedirect();

    $job = FixJob::query()->sole();

    expect($job->type)->toBe(FixJobType::Custom)
        ->and($job->status)->toBe(FixJobStatus::Pending)
        ->and($job->instructions)->toBe($instructions)
        ->and($job->fingerprint)->toBeNull()
        ->and($job->error_context)->toBeNull()
        ->and($job->base_sha)->toBe('')
        ->and($job->project_id)->toBe($project->id)
        ->and($job->project_repository_id)->toBe($repository->id);

    Queue::assertPushed(DispatchFixJob::class, fn (DispatchFixJob $queued): bool => $queued->uuid === $job->uuid);
});

test('the redirect lands on the new job so the run can be watched', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => 'Add a /healthz endpoint that returns 204 and touches no database.',
        ]);

    $job = FixJob::query()->sole();

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk();
});

test('the instructions are trimmed before they are measured and stored', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => "   Add a /healthz endpoint that returns 204.   \n",
        ])
        ->assertRedirect();

    expect(FixJob::query()->sole()->instructions)->toBe('Add a /healthz endpoint that returns 204.');
});

test('a request that says almost nothing is refused', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), ['project' => 'checkout', 'instructions' => 'fix it'])
        ->assertSessionHasErrors('instructions');

    expect(FixJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('an empty request is refused', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), ['project' => 'checkout', 'instructions' => '   '])
        ->assertSessionHasErrors('instructions');

    expect(FixJob::query()->count())->toBe(0);
});

test('a request longer than the cap is refused', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => str_repeat('a', 10001),
        ])
        ->assertSessionHasErrors('instructions');

    expect(FixJob::query()->count())->toBe(0);
});

test('a request exactly at the cap is accepted', function () {
    [$user, $team] = customJobTeam();

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => str_repeat('a', 10000),
        ])
        ->assertSessionHasNoErrors();

    expect(FixJob::query()->count())->toBe(1);
});

test('a project whose repository has not opted into autofix cannot be given work', function () {
    [$user, $team, $project] = customJobTeam();

    $project->repository()->update(['autofix_enabled' => false]);

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => 'Add a /healthz endpoint that returns 204.',
        ])
        ->assertSessionHasErrors('project');

    expect(FixJob::query()->count())->toBe(0);
});

test('a project with no repository at all cannot be given work', function () {
    [$user, $team] = customJobTeam();

    Project::factory()->forTeam($team)->create(['name' => 'Docs', 'slug' => 'docs']);

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => 'docs',
            'instructions' => 'Add a /healthz endpoint that returns 204.',
        ])
        ->assertSessionHasErrors('project');

    expect(FixJob::query()->count())->toBe(0);
});

test('a project belonging to another team is not reachable', function () {
    [$user, $team] = customJobTeam();
    [, , , $otherRepository] = customJobTeam();

    $otherProject = $otherRepository->project;
    $otherProject->update(['slug' => 'billing']);

    $this->actingAs($user)
        ->post(customJobUrl($team), [
            'project' => $otherProject->slug,
            'instructions' => 'Add a /healthz endpoint that returns 204.',
        ])
        ->assertSessionHasErrors('project');

    expect(FixJob::query()->count())->toBe(0);
});

test('a user who is not a member of the team cannot reach the endpoint at all', function () {
    [, $team] = customJobTeam();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(customJobUrl($team), [
            'project' => 'checkout',
            'instructions' => 'Add a /healthz endpoint that returns 204.',
        ])
        ->assertForbidden();

    expect(FixJob::query()->count())->toBe(0);
});

test('a guest is sent to log in', function () {
    [, $team] = customJobTeam();

    $this->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ])->assertRedirect(route('login'));
});

test('the concurrency budget refuses a custom job and names itself', function () {
    [$user, $team, , $repository] = customJobTeam(['max_concurrent' => 1]);

    FixJob::factory()->forRepository($repository)->running()->create();

    $response = $this->actingAs($user)->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ]);

    $response->assertSessionHasErrors('project');

    expect(session('errors')->first('project'))
        ->toContain('acme/checkout')
        ->toContain('in flight')
        ->and(FixJob::query()->where('type', FixJobType::Custom)->count())->toBe(0);
});

test('the daily budget refuses a custom job and names itself', function () {
    [$user, $team, , $repository] = customJobTeam(['max_concurrent' => 10, 'daily_budget' => 2]);

    FixJob::factory()->forRepository($repository)->merged()->count(2)->create(['created_at' => now()->subHour()]);

    $response = $this->actingAs($user)->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ]);

    $response->assertSessionHasErrors('project');

    expect(session('errors')->first('project'))
        ->toContain('daily budget')
        ->and(FixJob::query()->where('type', FixJobType::Custom)->count())->toBe(0);
});

test('custom jobs consume the same daily budget the scan draws from', function () {
    [$user, $team, , $repository] = customJobTeam(['max_concurrent' => 10, 'daily_budget' => 2]);

    /* One error job already spent today, so exactly one custom job may follow. */
    FixJob::factory()->forRepository($repository)->merged()->create(['created_at' => now()->subHour()]);

    $this->actingAs($user)->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /readyz endpoint that returns 204 as well.',
    ])->assertSessionHasErrors('project');

    expect(FixJob::query()->where('type', FixJobType::Custom)->count())->toBe(1);
});

test('a custom job in flight consumes a concurrency slot the scan would have used', function () {
    [$user, $team, , $repository] = customJobTeam(['max_concurrent' => 1]);

    $this->actingAs($user)->post(customJobUrl($team), [
        'project' => 'checkout',
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ])->assertSessionHasNoErrors();

    expect(app(FixJobBudget::class)->availableSlots($repository->fresh()))->toBe(0);
});

test('the index renders a custom job without an exception or a fingerprint', function () {
    [$user, $team, , $repository] = customJobTeam();

    FixJob::factory()
        ->forRepository($repository)
        ->custom('Upgrade guzzlehttp/guzzle to the latest 7.x release and leave the suite passing.')
        ->create();

    $this->actingAs($user)
        ->get(route('autofix.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('autofix/Index')
            ->has('jobs.data', 1)
            ->where('jobs.data.0.type', 'custom')
            ->where('jobs.data.0.typeLabel', 'Custom')
            ->where('jobs.data.0.fingerprint', null)
            ->where('jobs.data.0.exception', null)
            ->where('jobs.data.0.occurrences', null)
            ->where('jobs.data.0.title', 'Upgrade guzzlehttp/guzzle to the latest 7.x release and leave the suite passing.')
            ->has('autofixProjects', 1)
            ->where('autofixProjects.0.slug', 'checkout'),
        );
});

test('the detail page renders a custom job with its instructions and no error stats', function () {
    [$user, $team, , $repository] = customJobTeam();

    $job = FixJob::factory()
        ->forRepository($repository)
        ->custom('Add a /healthz endpoint that returns 204 and touches no database.')
        ->create(['status' => FixJobStatus::Merged]);

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('autofix/Show')
            ->where('job.type', 'custom')
            ->where('job.instructions', 'Add a /healthz endpoint that returns 204 and touches no database.')
            ->where('job.errorContext', null)
            ->where('job.fingerprint', null)
            ->where('job.stack', null)
            ->where('job.firstSeen', null)
            ->where('job.lastSeen', null),
        );
});

test('the index offers no project to pick when nothing has opted in', function () {
    [$user, $team, $project] = customJobTeam();

    $project->repository()->update(['autofix_enabled' => false]);

    $this->actingAs($user)
        ->get(route('autofix.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasRepository', true)
            ->has('autofixProjects', 0),
        );
});
