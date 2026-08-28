<?php

use App\Enums\FixJobStatus;
use App\Http\Middleware\VerifyGitHubSignature;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['autofix.github.webhook_secret' => 'webhook-secret']);
});

/**
 * POST one webhook delivery, signed the way GitHub signs it.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(string $event, array $payload, ?string $signature = null): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call(
        'POST',
        route('webhooks.github'),
        [],
        [],
        [],
        array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? VerifyGitHubSignature::signature($body, 'webhook-secret'),
        ], fn (?string $value): bool => $value !== null),
        $body,
    );
}

/**
 * An installation with a repository connected to a project.
 *
 * @return array{0: GitHubInstallation, 1: ProjectRepository}
 */
function connectedRepository(int $installationId = 4242, string $repo = 'acme/app'): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->forTeam($team)->create();
    $installation = GitHubInstallation::factory()->forTeam($team)->create(['installation_id' => $installationId]);

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create(['repo_full_name' => $repo]);

    return [$installation, $repository];
}

test('a delivery with a valid signature is accepted', function () {
    connectedRepository();

    postWebhook('installation', [
        'action' => 'created',
        'installation' => ['id' => 4242, 'account' => ['login' => 'acme-inc', 'type' => 'Organization']],
    ])->assertOk()->assertJson(['handled' => true]);
});

test('a delivery with a wrong signature is refused', function () {
    postWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 4242]], 'sha256=deadbeef')
        ->assertUnauthorized();
});

test('a delivery with no signature at all is refused', function () {
    postWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 4242]], '')
        ->assertUnauthorized();
});

test('a delivery is refused with a body that was signed differently', function () {
    postWebhook(
        'installation',
        ['action' => 'deleted', 'installation' => ['id' => 4242]],
        VerifyGitHubSignature::signature('{"action":"created"}', 'webhook-secret'),
    )->assertUnauthorized();
});

test('deliveries are unavailable rather than unauthorized when no secret is configured', function () {
    config(['autofix.github.webhook_secret' => null]);

    postWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 4242]])
        ->assertStatus(503);
});

test('the webhook route is exempt from request forgery verification', function () {
    $property = new ReflectionProperty(PreventRequestForgery::class, 'neverVerify');

    expect($property->getValue())->toContain('webhooks/github');
});

test('a created installation refreshes the account it is known under', function () {
    [$installation] = connectedRepository();

    postWebhook('installation', [
        'action' => 'created',
        'installation' => ['id' => 4242, 'account' => ['login' => 'acme-renamed', 'type' => 'User']],
    ])->assertOk();

    expect($installation->fresh()->account_login)->toBe('acme-renamed')
        ->and($installation->fresh()->account_type)->toBe('User');
});

test('a created installation nobody has linked to a team is ignored', function () {
    postWebhook('installation', [
        'action' => 'created',
        'installation' => ['id' => 999999, 'account' => ['login' => 'stranger', 'type' => 'User']],
    ])->assertNoContent();

    expect(GitHubInstallation::query()->count())->toBe(0);
});

test('a deleted installation is removed with everything hanging off it', function () {
    [$installation, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->prOpened()->create();

    postWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 4242]])
        ->assertOk();

    expect(GitHubInstallation::query()->whereKey($installation->id)->exists())->toBeFalse()
        ->and(ProjectRepository::query()->whereKey($repository->id)->exists())->toBeFalse()
        ->and(FixJob::query()->whereKey($job->id)->exists())->toBeFalse();
});

test('a deleted installation nobody knows is ignored', function () {
    postWebhook('installation', ['action' => 'deleted', 'installation' => ['id' => 12345]])
        ->assertNoContent();
});

test('removed repositories are disabled but kept', function () {
    [, $repository] = connectedRepository();
    [, $other] = connectedRepository(5252, 'acme/other');

    postWebhook('installation_repositories', [
        'action' => 'removed',
        'installation' => ['id' => 4242],
        'repositories_removed' => [['full_name' => 'acme/app']],
    ])->assertOk();

    expect($repository->fresh()->autofix_enabled)->toBeFalse()
        ->and($other->fresh()->autofix_enabled)->toBeTrue();
});

test('added repositories are left to the settings flow', function () {
    connectedRepository();

    postWebhook('installation_repositories', [
        'action' => 'added',
        'installation' => ['id' => 4242],
        'repositories_added' => [['full_name' => 'acme/new']],
    ])->assertNoContent();
});

test('a merged pull request moves its job to merged', function () {
    [, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->prOpened()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => ['number' => 42, 'merged' => true],
    ])->assertOk();

    expect($job->fresh()->status)->toBe(FixJobStatus::Merged)
        ->and($job->fresh()->failure_reason)->toBeNull()
        ->and($job->fresh()->completed_at)->not->toBeNull();
});

test('a pull request closed without merging rejects its job', function () {
    [, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->prOpened()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => ['number' => 42, 'merged' => false],
    ])->assertOk();

    expect($job->fresh()->status)->toBe(FixJobStatus::Rejected)
        ->and($job->fresh()->failure_reason)->toBe('pr_closed_unmerged');
});

test('a pull request matching no job is ignored', function () {
    [, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->prOpened()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => ['number' => 99, 'merged' => true],
    ])->assertNoContent();

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);
});

test('a pull request number from another repository is ignored', function () {
    [, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->prOpened()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'someone/else'],
        'pull_request' => ['number' => 42, 'merged' => true],
    ])->assertNoContent();

    expect($job->fresh()->status)->toBe(FixJobStatus::PrOpened);
});

test('a pull request that is only opened is ignored', function () {
    [, $repository] = connectedRepository();
    FixJob::factory()->forRepository($repository)->prOpened()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'opened',
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => ['number' => 42, 'merged' => false],
    ])->assertNoContent();
});

test('an already merged job is not reopened by a second delivery', function () {
    [, $repository] = connectedRepository();
    $job = FixJob::factory()->forRepository($repository)->merged()->create(['pr_number' => 42]);

    postWebhook('pull_request', [
        'action' => 'closed',
        'repository' => ['full_name' => 'acme/app'],
        'pull_request' => ['number' => 42, 'merged' => false],
    ])->assertNoContent();

    expect($job->fresh()->status)->toBe(FixJobStatus::Merged);
});

test('any other event is acknowledged and ignored', function () {
    postWebhook('push', ['ref' => 'refs/heads/main'])->assertNoContent();
    postWebhook('ping', ['zen' => 'Non-blocking is better than blocking.'])->assertNoContent();
});
