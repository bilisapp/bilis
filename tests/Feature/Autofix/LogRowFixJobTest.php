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
use Illuminate\Support\Facades\Queue;

/**
 * A team whose one project ships from a repository that takes every service.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: ProjectRepository}
 */
function logFixTeam(array $services = []): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create();

    $factory = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled();

    if ($services !== []) {
        $factory = $factory->forServices($services);
    }

    $repository = $factory->create(['repo_full_name' => 'acme/checkout', 'default_branch' => 'main']);

    return [$user, $team, $project, $repository];
}

/**
 * The URL a log line is handed to the agent at.
 */
function logFixUrl(Team $team): string
{
    return route('autofix.from-log', ['current_team' => $team->slug]);
}

/**
 * A log row in the shape the viewer holds and posts back.
 *
 * @return array<string, mixed>
 */
function logFixRow(Project $project, array $overrides = []): array
{
    return [
        'project' => (string) $project->getKey(),
        'timestamp' => '2026-08-26 10:00:00.000000000',
        'severityText' => 'ERROR',
        'severityNumber' => 17,
        'serviceName' => 'api',
        'body' => "App\\Exceptions\\PaymentFailed: card declined\n#0 /app/app/Services/Billing/Charge.php(42): charge()",
        'traceId' => 'trace-1',
        'spanId' => 'span-1',
        'scopeName' => 'bilis.ingest',
        'scopeVersion' => '1.0',
        'logAttributes' => ['request_id' => 'abc'],
        'resourceAttributes' => ['host' => 'web-1'],
        ...$overrides,
    ];
}

beforeEach(function () {
    config()->set('autofix.enabled', true);
    config()->set('autofix.llm.api_key', 'sk-ant-instance-fallback');

    Queue::fake();
});

test('a team member can raise a fix job from one log line', function () {
    [$user, $team, $project, $repository] = logFixTeam();

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertRedirect();

    $job = FixJob::query()->sole();

    expect($job->type)->toBe(FixJobType::Error)
        ->and($job->status)->toBe(FixJobStatus::Pending)
        ->and($job->project_repository_id)->toBe($repository->id)
        ->and($job->fingerprint)->not->toBeEmpty()
        ->and($job->instructions)->toBeNull();

    // The line itself is what the agent is given, frozen at the moment it was
    // raised: the row may age out of retention long before the PR is read.
    expect($job->error_context['count'])->toBe(1)
        ->and($job->error_context['service_name'])->toBe('api')
        ->and($job->error_context['exception'])->toContain('PaymentFailed')
        ->and($job->error_context['samples'])->toHaveCount(1)
        ->and($job->error_context['first_seen'])->toBe('2026-08-26 10:00:00.000000000');

    Queue::assertPushed(DispatchFixJob::class);
});

test('the scan minimum does not apply to a line somebody pointed at', function () {
    [$user, $team, $project] = logFixTeam();

    // The scheduled path needs five occurrences before it will spend a run.
    config()->set('autofix.defaults.min_error_count', 5);

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertRedirect();

    expect(FixJob::query()->count())->toBe(1);
});

test('the repository is derived from the service rather than named by the browser', function () {
    [$user, $team, $project] = logFixTeam(['workers']);

    $installation = GitHubInstallation::factory()->forTeam($team)->create();
    $api = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->forServices(['api'])
        ->create(['repo_full_name' => 'acme/api']);

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project, ['serviceName' => 'api']))
        ->assertRedirect();

    expect(FixJob::query()->sole()->project_repository_id)->toBe($api->id);
});

test('a service no repository claims is refused', function () {
    [$user, $team, $project] = logFixTeam(['workers']);

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project, ['serviceName' => 'api']))
        ->assertSessionHasErrors('repository');

    expect(FixJob::query()->count())->toBe(0);
});

test('a project from another team is not a project at all', function () {
    [$user, $team] = logFixTeam();

    $other = Team::factory()->create();
    $stranger = Project::factory()->forTeam($other)->create();

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($stranger))
        ->assertSessionHasErrors('project');

    expect(FixJob::query()->count())->toBe(0);
});

test('a second asker is shown the job already running rather than charged for a duplicate', function () {
    [$user, $team, $project, $repository] = logFixTeam();

    $this->actingAs($user)->post(logFixUrl($team), logFixRow($project));

    $first = FixJob::query()->sole();

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertRedirect(route('autofix.show', [$team->slug, $first->uuid]));

    expect(FixJob::query()->count())->toBe(1);
});

test('a settled fingerprint can be tried again', function () {
    [$user, $team, $project] = logFixTeam();

    $this->actingAs($user)->post(logFixUrl($team), logFixRow($project));

    FixJob::query()->sole()->forceFill([
        'status' => FixJobStatus::Failed,
        'completed_at' => now(),
    ])->save();

    $this->actingAs($user)->post(logFixUrl($team), logFixRow($project))->assertRedirect();

    expect(FixJob::query()->count())->toBe(2);
});

test('the repository budgets refuse a line the same way they refuse a custom job', function () {
    [$user, $team, $project, $repository] = logFixTeam();

    $repository->forceFill(['daily_budget' => 1])->save();

    $this->actingAs($user)->post(logFixUrl($team), logFixRow($project));

    // A different error, so the duplicate guard is not what refuses it.
    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project, ['body' => 'RuntimeException: something else entirely']))
        ->assertSessionHasErrors('repository');

    expect(FixJob::query()->count())->toBe(1);
});

test('a team without a model key is told where to add one', function () {
    [$user, $team, $project] = logFixTeam();

    config()->set('autofix.llm.api_key', null);

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertSessionHasErrors('credential');

    expect(FixJob::query()->count())->toBe(0);
});

test('the endpoint does not exist with autofix switched off', function () {
    [$user, $team, $project] = logFixTeam();

    config()->set('autofix.enabled', false);

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertNotFound();
});

test('a repository that has not opted in is not a fix target', function () {
    [$user, $team, $project, $repository] = logFixTeam();

    $repository->forceFill(['autofix_enabled' => false])->save();

    $this->actingAs($user)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertSessionHasErrors('repository');
});

test('a user outside the team cannot raise anything', function () {
    [, $team, $project] = logFixTeam();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(logFixUrl($team), logFixRow($project))
        ->assertForbidden();
});
