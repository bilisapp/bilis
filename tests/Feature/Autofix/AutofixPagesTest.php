<?php

use App\Enums\FixJobStatus;
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
 * A team with one owner and a project that ships from a connected repository.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: ProjectRepository}
 */
function autofixJobsTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create();
    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    return [$user, $team, $project, $repository];
}

test('the autofix index lists the fix jobs of the current team only', function () {
    [$user, $team, , $repository] = autofixJobsTeam();

    FixJob::factory()->forRepository($repository)->create([
        'status' => FixJobStatus::PrOpened,
        'pr_number' => 42,
        'pr_url' => 'https://github.com/acme/checkout/pull/42',
        'error_context' => [
            'exception' => 'App\\Exceptions\\PaymentFailed',
            'service_name' => 'checkout',
            'count' => 9,
        ],
    ]);

    FixJob::factory()->create();

    $this->actingAs($user)
        ->get(route('autofix.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('autofix/Index')
            ->where('hasRepository', true)
            ->has('jobs.data', 1)
            ->where('jobs.data.0.exception', 'App\\Exceptions\\PaymentFailed')
            ->where('jobs.data.0.status', 'pr_opened')
            ->where('jobs.data.0.statusLabel', 'PR opened')
            ->where('jobs.data.0.prNumber', 42)
            ->where('jobs.data.0.project.slug', 'checkout')
            ->where('jobs.data.0.occurrences', 9),
        );
});

test('the index reports when no repository is connected yet', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('autofix.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('autofix/Index')
            ->where('hasRepository', false)
            ->has('jobs.data', 0),
        );
});

test('the index can be narrowed by project and status', function () {
    [$user, $team, , $repository] = autofixJobsTeam();

    FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Merged]);
    FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Failed]);

    $this->actingAs($user)
        ->get(route('autofix.index', [
            'current_team' => $team->slug,
            'project' => 'checkout',
            'status' => 'merged',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs.data', 1)
            ->where('jobs.data.0.status', 'merged')
            ->where('filters.project', 'checkout')
            ->where('filters.status', 'merged'),
        );
});

test('the detail page ships the transcript, the diff and no stream for a finished job', function () {
    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create([
        'status' => FixJobStatus::Merged,
        'diff' => "diff --git a/a.php b/a.php\n",
        'report' => ['tests' => 'passed'],
        'events' => [
            ['seq' => 1, 'ts' => '2026-08-27T09:00:00Z', 'type' => 'phase', 'data' => ['phase' => 'clone']],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('autofix/Show')
            ->where('stream', null)
            ->where('canCancel', false)
            ->has('job.events', 1)
            ->where('job.events.0.type', 'phase')
            ->where('job.diff', "diff --git a/a.php b/a.php\n")
            ->where('job.report.tests', 'passed'),
        );
});

test('the detail page offers a stream while the job is active', function () {
    config()->set('autofix.ayos.stream_url', 'https://agents.bilis.test');
    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Running]);

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stream.url', 'https://agents.bilis.test/jobs/'.$job->uuid.'/stream')
            ->where('stream.ttlMinutes', 10)
            ->where('canCancel', true),
        );
});

test('no stream is offered when the instance has no ayos configured', function () {
    config()->set('autofix.ayos.stream_url', null);

    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Running]);

    $this->actingAs($user)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stream', null));
});

test('a fix job from another team is not reachable', function () {
    [, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create();

    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider, ['role' => TeamRole::Owner->value]);

    $this->actingAs($outsider)
        ->get(route('autofix.show', ['current_team' => $otherTeam->slug, 'fixJob' => $job->uuid]))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertForbidden();
});

test('guests are redirected away from the autofix pages', function () {
    $team = Team::factory()->create();

    $this->get(route('autofix.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('cancelling a job asks ayos to abort it and records the outcome', function () {
    config()->set('autofix.ayos.url', 'https://ayos.test');
    config()->set('autofix.ayos.shared_secret', 'secret');

    Http::fake(['ayos.test/*' => Http::response(['ok' => true])]);

    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Running]);

    $this->actingAs($user)
        ->from(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->post(route('autofix.cancel', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertRedirect();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ayos.test/jobs/'.$job->uuid.'/cancel');

    expect($job->fresh()->status)->toBe(FixJobStatus::Cancelled)
        ->and($job->fresh()->completed_at)->not->toBeNull();
});

test('a job that has already come to rest cannot be cancelled', function () {
    Http::fake();

    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Merged]);

    $this->actingAs($user)
        ->post(route('autofix.cancel', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertForbidden();

    Http::assertNothingSent();
    expect($job->fresh()->status)->toBe(FixJobStatus::Merged);
});

test('an unreachable ayos leaves the job running rather than lying about it', function () {
    config()->set('autofix.ayos.url', 'https://ayos.test');
    config()->set('autofix.ayos.shared_secret', 'secret');

    Http::fake(['ayos.test/*' => Http::response('nope', 500)]);

    [$user, $team, , $repository] = autofixJobsTeam();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => FixJobStatus::Running]);

    $this->actingAs($user)
        ->from(route('autofix.show', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->post(route('autofix.cancel', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertRedirect();

    expect($job->fresh()->status)->toBe(FixJobStatus::Running);
});
