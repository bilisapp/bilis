<?php

use App\Enums\FixJobStatus;
use App\Enums\TeamRole;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\GitHubAppTokenService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Build a team with one owner.
 *
 * @return array{0: User, 1: Team}
 */
function autofixTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return [$user, $team];
}

test('an installation belongs to a team and owns its repositories', function () {
    [, $team] = autofixTeam();

    $installation = GitHubInstallation::factory()->forTeam($team)->create();
    $repository = ProjectRepository::factory()->forInstallation($installation)->create();

    expect($installation->team->is($team))->toBeTrue()
        ->and($installation->repositories->pluck('id')->all())->toBe([$repository->id])
        ->and($team->githubInstallations->pluck('id')->all())->toBe([$installation->id])
        ->and($installation->installation_id)->toBeInt();
});

test('a repository belongs to a project and an installation', function () {
    $project = Project::factory()->create();
    $installation = GitHubInstallation::factory()->create();

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->withTestCommand()
        ->create();

    expect($repository->project->is($project))->toBeTrue()
        ->and($repository->installation->is($installation))->toBeTrue()
        ->and($repository->autofix_enabled)->toBeTrue()
        ->and($repository->test_cmd)->toBe('php artisan test --compact')
        ->and($project->repositories->pluck('id')->all())->toBe([$repository->id]);
});

test('a repository does not opt into autofix by default', function () {
    expect(ProjectRepository::factory()->create()->autofix_enabled)->toBeFalse();
});

test('a fix job is created with a uuid and casts its json columns', function () {
    $job = FixJob::factory()->create();

    expect(Str::isUuid($job->uuid))->toBeTrue()
        ->and($job->getRouteKey())->toBe($job->uuid)
        ->and($job->status)->toBe(FixJobStatus::Pending)
        ->and($job->error_context)->toBeArray()
        ->and($job->error_context['exception'])->toBe('RuntimeException');
});

test('a fix job belongs to its project and repository, which agree on the project', function () {
    $job = FixJob::factory()->create();

    expect($job->project)->toBeInstanceOf(Project::class)
        ->and($job->repository)->toBeInstanceOf(ProjectRepository::class)
        ->and($job->repository->project_id)->toBe($job->project_id)
        ->and($job->project->fixJobs->pluck('id')->all())->toBe([$job->id])
        ->and($job->repository->fixJobs->pluck('id')->all())->toBe([$job->id]);
});

test('a fix job can be made for a given repository', function () {
    $repository = ProjectRepository::factory()->create();

    $job = FixJob::factory()->forRepository($repository)->create();

    expect($job->project_repository_id)->toBe($repository->id)
        ->and($job->project_id)->toBe($repository->project_id);
});

test('the factory states cover the job lifecycle', function (string $state, FixJobStatus $status) {
    $job = FixJob::factory()->{$state}()->create();

    expect($job->status)->toBe($status)
        ->and($job->dispatched_at)->not->toBeNull();
})->with([
    ['dispatched', FixJobStatus::Dispatched],
    ['running', FixJobStatus::Running],
    ['prOpened', FixJobStatus::PrOpened],
    ['merged', FixJobStatus::Merged],
    ['rejected', FixJobStatus::Rejected],
    ['failed', FixJobStatus::Failed],
    ['timedOut', FixJobStatus::Timeout],
    ['cancelled', FixJobStatus::Cancelled],
]);

test('an opened pull request is recorded on the job', function () {
    $job = FixJob::factory()->prOpened()->create();

    expect($job->pr_number)->toBeInt()
        ->and($job->pr_url)->toStartWith('https://github.com/')
        ->and($job->report)->toBeArray()
        ->and($job->completed_at)->not->toBeNull();
});

test('only the in flight statuses count as active', function () {
    expect(FixJobStatus::PrOpened->isActive())->toBeTrue()
        ->and(FixJobStatus::PrOpened->isTerminal())->toBeFalse()
        ->and(FixJobStatus::Merged->isTerminal())->toBeTrue()
        ->and(FixJobStatus::Rejected->isTerminal())->toBeTrue()
        ->and(FixJobStatus::values())->toContain('pr_opened');
});

test('a fix job resolves through the team in the route', function () {
    [$user, $team] = autofixTeam();

    Route::get('/{current_team}/_test/autofix/{fixJob}', fn (string $currentTeam, FixJob $fixJob) => ['uuid' => $fixJob->uuid])
        ->middleware(['web', 'auth']);

    $project = Project::factory()->forTeam($team)->create();
    $job = FixJob::factory()->forProject($project)->create();

    $this->actingAs($user)
        ->get("/{$team->slug}/_test/autofix/{$job->uuid}")
        ->assertOk()
        ->assertJson(['uuid' => $job->uuid]);
});

test('a fix job from another team is a 404', function () {
    [$user, $team] = autofixTeam();

    Route::get('/{current_team}/_test/autofix/{fixJob}', fn (string $currentTeam, FixJob $fixJob) => ['uuid' => $fixJob->uuid])
        ->middleware(['web', 'auth']);

    $job = FixJob::factory()->create();

    $this->actingAs($user)
        ->get("/{$team->slug}/_test/autofix/{$job->uuid}")
        ->assertNotFound();
});

test('the autofix config carries the runner, github credentials and defaults', function () {
    expect(config('autofix.defaults.timeout_s'))->toBe(900)
        ->and(config('autofix.defaults.max_diff_lines'))->toBe(800)
        ->and(config('autofix.defaults.min_error_count'))->toBe(5)
        ->and(config('autofix.defaults.cooldown_days'))->toBe(7)
        ->and(config('autofix.defaults.path_denylist'))->toBe(['.github/**', '.env*'])
        ->and(config('autofix.stream_jwt.ttl_minutes'))->toBe(10)
        ->and(config()->has('autofix.enabled'))->toBeTrue()
        ->and(config()->has('autofix.runner.driver'))->toBeTrue()
        ->and(config()->has('autofix.runner.local.entrypoint'))->toBeTrue()
        ->and(config()->has('autofix.runner.scaleway.job_definition_id'))->toBeTrue()
        ->and(config()->has('autofix.github.app_id'))->toBeTrue()
        ->and(config()->has('autofix.github.private_key'))->toBeTrue()
        ->and(config()->has('autofix.github.webhook_secret'))->toBeTrue()
        ->and(config()->has('autofix.stream_jwt.private_key'))->toBeTrue()
        ->and(config()->has('autofix.llm.api_key'))->toBeTrue();
});

test('the token service is resolvable from the container', function () {
    expect(app(GitHubAppTokenService::class))->toBeInstanceOf(GitHubAppTokenService::class);
});
