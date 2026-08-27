<?php

use App\Enums\FixJobStatus;
use App\Enums\TeamRole;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\StreamTokenIssuer;

/**
 * A team with one owner, a project, a connected repository and one fix job.
 *
 * @return array{0: User, 1: Team, 2: FixJob}
 */
function streamTokenJob(FixJobStatus $status = FixJobStatus::Running): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout']);
    $installation = GitHubInstallation::factory()->forTeam($team)->create();
    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->create();

    $job = FixJob::factory()->forRepository($repository)->create(['status' => $status]);

    return [$user, $team, $job];
}

/**
 * Configure a real Ed25519 keypair and hand back the public half.
 */
function streamTokenKeypair(): string
{
    $keypair = sodium_crypto_sign_keypair();

    config()->set('autofix.stream_jwt.private_key', base64_encode(sodium_crypto_sign_secretkey($keypair)));
    config()->set('autofix.stream_jwt.ttl_minutes', 10);
    config()->set('autofix.ayos.stream_url', 'https://agents.bilis.test');

    return sodium_crypto_sign_publickey($keypair);
}

/**
 * Split a JWT into its decoded header, payload and raw signing material.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
 */
function decodeStreamJwt(string $token): array
{
    [$header, $payload, $signature] = explode('.', $token);

    $decode = fn (string $segment): string => (string) base64_decode(
        strtr($segment, '-_', '+/').str_repeat('=', (4 - strlen($segment) % 4) % 4),
        true,
    );

    return [
        json_decode($decode($header), true),
        json_decode($decode($payload), true),
        $header.'.'.$payload,
        $decode($signature),
    ];
}

test('a stream token is signed with ed25519 and scoped to one job', function () {
    $publicKey = streamTokenKeypair();
    [$user, $team, $job] = streamTokenJob();

    $response = $this->actingAs($user)
        ->post(route('autofix.stream-token', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertOk()
        ->assertJsonStructure(['token', 'stream_url', 'expires_at']);

    expect($response->json('stream_url'))
        ->toBe('https://agents.bilis.test/jobs/'.$job->uuid.'/stream');

    [$header, $payload, $signingInput, $signature] = decodeStreamJwt((string) $response->json('token'));

    expect($header)->toBe(['alg' => 'EdDSA', 'typ' => 'JWT'])
        ->and($payload['sub'])->toBe((string) $user->id)
        ->and($payload['job'])->toBe($job->uuid)
        ->and($payload['scope'])->toBe('stream:read')
        ->and(sodium_crypto_sign_verify_detached($signature, $signingInput, $publicKey))->toBeTrue();
});

test('a stream token expires after the configured ttl', function () {
    streamTokenKeypair();
    config()->set('autofix.stream_jwt.ttl_minutes', 3);

    [$user, $team, $job] = streamTokenJob();

    $this->freezeTime();

    $token = $this->actingAs($user)
        ->post(route('autofix.stream-token', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->json('token');

    [, $payload] = decodeStreamJwt((string) $token);

    expect($payload['exp'] - $payload['iat'])->toBe(180);
});

test('a token is refused once the job has come to rest', function (FixJobStatus $status) {
    streamTokenKeypair();
    [$user, $team, $job] = streamTokenJob($status);

    $this->actingAs($user)
        ->post(route('autofix.stream-token', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertForbidden();
})->with([
    'merged' => [FixJobStatus::Merged],
    'failed' => [FixJobStatus::Failed],
    'cancelled' => [FixJobStatus::Cancelled],
]);

test('a member of another team cannot mint a token for a job', function () {
    streamTokenKeypair();
    [, $team, $job] = streamTokenJob();

    $outsider = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($outsider, ['role' => TeamRole::Owner->value]);

    // Reached through their own team, the job does not resolve at all.
    $this->actingAs($outsider)
        ->post(route('autofix.stream-token', ['current_team' => $otherTeam->slug, 'fixJob' => $job->uuid]))
        ->assertNotFound();

    // Reached through the owning team, membership is what turns them away.
    $this->actingAs($outsider)
        ->post(route('autofix.stream-token', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertForbidden();
});

test('a stream token cannot be minted without a signing key', function () {
    config()->set('autofix.stream_jwt.private_key', null);
    config()->set('autofix.ayos.stream_url', 'https://agents.bilis.test');

    [$user, $team, $job] = streamTokenJob();

    $this->actingAs($user)
        ->post(route('autofix.stream-token', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertStatus(503);
});

test('the issuer accepts a 32 byte seed as well as a full secret key', function () {
    $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $keypair = sodium_crypto_sign_seed_keypair($seed);

    config()->set('autofix.stream_jwt.private_key', base64_encode($seed));
    config()->set('autofix.ayos.stream_url', 'https://agents.bilis.test');

    [$user, , $job] = streamTokenJob();

    $token = app(StreamTokenIssuer::class)->issue($job, $user);

    [, , $signingInput, $signature] = decodeStreamJwt($token['token']);

    expect(sodium_crypto_sign_verify_detached(
        $signature,
        $signingInput,
        sodium_crypto_sign_publickey($keypair),
    ))->toBeTrue();
});
