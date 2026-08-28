<?php

use App\Enums\FixJobStatus;
use App\Enums\TeamRole;
use App\Http\Middleware\VerifyAyosSignature;
use App\Models\FixJob;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\FixJobEventRecorder;
use App\Services\Autofix\RunKeyPair;
use App\Services\Autofix\StreamTokenIssuer;
use Illuminate\Testing\TestResponse;

/**
 * The inverted event stream, end to end within Bilis.
 *
 * A container run has nothing listening, so it POSTs batches of events here and
 * this application fans them out to the browser. That deleted Ayos's ring
 * buffer, its SSE endpoint and its cross-origin token check, and moved the
 * awkward parts — duplicate batches, out-of-order batches, missing batches —
 * onto a merge this side controls.
 */
function eventJob(array $attributes = []): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $project = Project::factory()->forTeam($team)->create();
    $repository = ProjectRepository::factory()->forProject($project)->create();

    $keys = RunKeyPair::mint();

    $job = FixJob::factory()->forRepository($repository)->create([
        'status' => FixJobStatus::Dispatched,
        'ayos_public_key' => $keys->publicKey,
        ...$attributes,
    ]);

    return [$job, $keys, $user, $team];
}

/**
 * POST a batch of events the way a run would.
 *
 * @param  list<array<string, mixed>>  $events
 */
function postEvents(FixJob $job, RunKeyPair $keys, array $events): TestResponse
{
    $body = (string) json_encode(['job_id' => $job->uuid, 'events' => $events]);
    $signedAt = (string) now()->getTimestamp();
    $secretKey = sodium_crypto_sign_secretkey(
        sodium_crypto_sign_seed_keypair((string) base64_decode($keys->signingKey, true)),
    );

    return test()->call('POST', route('api.internal.autofix.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_AYOS_TIMESTAMP' => $signedAt,
        'HTTP_X_AYOS_SIGNATURE' => VerifyAyosSignature::SIGNATURE_PREFIX.base64_encode(
            sodium_crypto_sign_detached($signedAt.'.'.$body, $secretKey),
        ),
    ], $body);
}

/**
 * One event.
 *
 * @return array<string, mixed>
 */
function jobEvent(int $seq, string $type = 'phase', array $data = ['state' => 'fixing']): array
{
    return ['seq' => $seq, 'ts' => '2026-08-27T10:00:00Z', 'type' => $type, 'data' => $data];
}

/* --------------------------------------------------------------- ingestion */

test('a signed batch is appended to the transcript', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(1), jobEvent(2, 'agent_message', ['text' => 'looking'])])
        ->assertAccepted()
        ->assertJson(['recorded' => 2]);

    expect($job->fresh()->events)->toHaveCount(2);
});

test('an unsigned batch is rejected and records nothing', function () {
    [$job] = eventJob();

    $body = (string) json_encode(['job_id' => $job->uuid, 'events' => [jobEvent(1)]]);

    $this->call('POST', route('api.internal.autofix.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    expect($job->fresh()->events)->toBeNull();
});

test('a batch signed by another run is rejected', function () {
    [$job] = eventJob();

    postEvents($job, RunKeyPair::mint(), [jobEvent(1)])->assertUnauthorized();

    expect($job->fresh()->events)->toBeNull();
});

/*
 * The first batch is the only signal that the container actually started.
 * Without it a job that boots slowly is indistinguishable from one that never
 * booted at all, until the reaper's deadline hours later.
 */
test('the first batch moves the job from dispatched to running', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(1)])->assertAccepted();

    expect($job->fresh()->status)->toBe(FixJobStatus::Running);
});

test('a batch for a job that has already finished is accepted and ignored', function () {
    [$job, $keys] = eventJob(['status' => FixJobStatus::PrOpened]);

    postEvents($job, $keys, [jobEvent(1)])
        ->assertOk()
        ->assertJson(['recorded' => 0, 'status' => 'pr_opened']);

    expect($job->fresh()->events)->toBeNull()
        ->and($job->fresh()->status)->toBe(FixJobStatus::PrOpened);
});

/* ------------------------------------------------------------------- merge */

/*
 * Delivery is best-effort by construction on the runner's side: a flush that
 * fails is retried, and the batches are independent requests. Both of those
 * land here as duplicates and reordering.
 */
test('a redelivered batch does not duplicate the transcript', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(1), jobEvent(2)])->assertAccepted();
    postEvents($job, $keys, [jobEvent(2), jobEvent(3)])
        ->assertAccepted()
        ->assertJson(['recorded' => 1]);

    expect(array_column($job->fresh()->events, 'seq'))->toBe([1, 2, 3]);
});

test('batches that arrive out of order are stored in order', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(5)])->assertAccepted();
    postEvents($job, $keys, [jobEvent(2)])->assertAccepted();
    postEvents($job, $keys, [jobEvent(9), jobEvent(7)])->assertAccepted();

    expect(array_column($job->fresh()->events, 'seq'))->toBe([2, 5, 7, 9]);
});

/*
 * The artifact's transcript is the authoritative one and may fill gaps the live
 * path dropped — but it is merged, never assigned over. An artifact arriving
 * with a truncated tail must not erase a transcript the viewer watched arrive.
 */
test('the artifact merges into the live transcript rather than replacing it', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(1), jobEvent(2)])->assertAccepted();

    app(FixJobEventRecorder::class)->record($job->fresh(), [jobEvent(2), jobEvent(3)]);

    expect(array_column($job->fresh()->events, 'seq'))->toBe([1, 2, 3]);
});

test('a malformed event is dropped rather than corrupting the transcript', function () {
    [$job, $keys] = eventJob();

    postEvents($job, $keys, [jobEvent(1), ['nonsense' => true], jobEvent(2)])->assertAccepted();

    expect(array_column($job->fresh()->events, 'seq'))->toBe([1, 2]);
});

/*
 * A runaway agent must not be able to grow a database row without bound. The
 * TAIL is what survives: the end of a transcript is where the failure is.
 */
test('the transcript is capped, keeping the most recent events', function () {
    [$job, $keys] = eventJob();

    $recorder = app(FixJobEventRecorder::class);

    $recorder->record($job, array_map(
        fn (int $seq): array => jobEvent($seq),
        range(1, FixJobEventRecorder::MAX_EVENTS + 50),
    ));

    $events = $job->fresh()->events;

    expect($events)->toHaveCount(FixJobEventRecorder::MAX_EVENTS)
        ->and(end($events)['seq'])->toBe(FixJobEventRecorder::MAX_EVENTS + 50);
});

/* ------------------------------------------------------------------ stream */

test('the stream replays the transcript and ends at done', function () {
    [$job, $keys, $user, $team] = eventJob();

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    postEvents($job, $keys, [
        jobEvent(1),
        jobEvent(2, 'agent_message', ['text' => 'changed Foo.php']),
        jobEvent(3, 'done', ['status' => 'done']),
    ])->assertAccepted();

    $token = app(StreamTokenIssuer::class)->issue($job->fresh(), $user);

    $response = $this->actingAs($user)->get(
        route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token='.$token['token'],
    )->assertOk();

    $body = $response->streamedContent();

    expect($body)->toContain('event: phase')
        ->toContain('event: agent_message')
        ->toContain('changed Foo.php')
        ->toContain('event: done')
        ->toContain('id: 3');
});

/*
 * `Last-Event-ID` is what makes a reconnect cheap: the transcript is a row, so
 * resuming is a filter rather than a replayed ring buffer.
 */
test('a reconnecting client resumes after the events it already has', function () {
    [$job, $keys, $user, $team] = eventJob();

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    postEvents($job, $keys, [
        jobEvent(1, 'agent_message', ['text' => 'first']),
        jobEvent(2, 'agent_message', ['text' => 'second']),
        jobEvent(3, 'done', ['status' => 'done']),
    ])->assertAccepted();

    $token = app(StreamTokenIssuer::class)->issue($job->fresh(), $user);

    $body = $this->actingAs($user)
        ->withHeaders(['Last-Event-ID' => '2'])
        ->get(route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token='.$token['token'])
        ->assertOk()
        ->streamedContent();

    expect($body)->not->toContain('first')
        ->not->toContain('second')
        ->toContain('event: done');
});

/*
 * The session is the authority — this is a same-origin route now. The token
 * only says WHICH job was asked for, which a session cannot.
 */
test('a token minted for another job is refused', function () {
    [$job, , $user, $team] = eventJob();
    [$other] = eventJob();

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    $token = app(StreamTokenIssuer::class)->issue($other, $user);

    $this->actingAs($user)
        ->get(route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token='.$token['token'])
        ->assertForbidden();
});

test('a member of another team cannot open the stream', function () {
    [$job, , , $team] = eventJob();

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token=whatever')
        ->assertForbidden();
});

test('a guest cannot open the stream', function () {
    [$job, , , $team] = eventJob();

    $this->get(route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]))
        ->assertRedirect(route('login'));
});

/*
 * A job that comes to rest without a `done` event — reaped, cancelled locally,
 * or failed before the run ever spoke. Authorisation happens at connect, so the
 * stream is already open when that happens, and the viewer needs it to end
 * rather than hang until the connection times out.
 */
test('a job that ends without a done event still closes the stream', function () {
    [$job, , $user, $team] = eventJob(['status' => FixJobStatus::Running]);

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    $token = app(StreamTokenIssuer::class)->issue($job, $user);

    $response = $this->actingAs($user)->get(
        route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token='.$token['token'],
    )->assertOk();

    // The job dies after the connection was authorised, which is exactly when
    // the reaper would do it.
    $job->forceFill(['status' => FixJobStatus::Failed])->save();

    expect($response->streamedContent())
        ->toContain('event: done')
        ->toContain('"status":"failed"');
});

/*
 * A finished job never opens a stream at all: the policy refuses it, and the
 * page renders the persisted transcript instead. There is nothing left to
 * watch, and a viewer reconnecting to one would poll a row that cannot change.
 */
test('a finished job is refused a stream outright', function () {
    [$job, , $user, $team] = eventJob(['status' => FixJobStatus::PrOpened]);

    config()->set('autofix.stream_jwt.private_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)));

    $this->actingAs($user)
        ->get(route('autofix.stream', ['current_team' => $team->slug, 'fixJob' => $job->uuid]).'?token=whatever')
        ->assertForbidden();
});
