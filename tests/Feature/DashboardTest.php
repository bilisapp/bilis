<?php

use App\Enums\TeamRole;
use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

/**
 * Build a dashboard team with two projects.
 *
 * The page issues five ClickHouse statements: hasAnyLogs, the system.parts
 * total, the per-project byte scan, and the digest's counts, top errors and
 * service liveness. Every test that renders it has to answer all five.
 *
 * @return array{0: User, 1: Team, 2: Project, 3: Project}
 */
function storageTeam(): array
{
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $api = Project::factory()->forTeam($team)->create(['name' => 'Api', 'slug' => 'api']);
    $worker = Project::factory()->forTeam($team)->create(['name' => 'Worker', 'slug' => 'worker']);

    return [$user, $team, $api, $worker];
}

test('dashboard reports storage per project, largest first', function () {
    [$user, $team, $api, $worker] = storageTeam();

    Http::fake(function (Request $request) use ($api, $worker) {
        $body = $request->body();

        if (str_contains($body, 'system.parts')) {
            return Http::response(json_encode(['Bytes' => '1000'])."\n");
        }

        if (str_contains($body, 'GROUP BY ProjectId')) {
            return Http::response(
                json_encode(['ProjectId' => (string) $worker->id, 'Rows' => '200', 'Bytes' => '1000'])."\n"
                .json_encode(['ProjectId' => (string) $api->id, 'Rows' => '600', 'Bytes' => '3000'])."\n",
            );
        }

        return digestResponse($body);
    });

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage.totalBytes', 1000)
        ->where('storage.unavailable', false)
        ->has('storage.projects', 2)
        ->where('storage.projects.0.name', 'Api')
        ->where('storage.projects.0.slug', 'api')
        ->where('storage.projects.0.rows', 600)
        ->where('storage.projects.0.bytes', 750)
        ->where('storage.projects.1.name', 'Worker')
        ->where('storage.projects.1.bytes', 250),
    );

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'GROUP BY ProjectId')) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && isset($query['param_projectIds']);
    });
});

test('dashboard storage is null for a team with no projects', function () {
    Http::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage', null),
    );

    Http::assertNothingSent();
});

test('an overloaded clickhouse marks storage unavailable without failing the page', function () {
    [$user, $team] = storageTeam();

    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Code: 202. Too many simultaneous queries', 503),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('storage.unavailable', true)
        ->where('storage.totalBytes', 0)
        ->has('storage.projects', 0),
    );
});

/**
 * Answer the digest statements — and hasAnyLogs — from one fake.
 *
 * Matched on substrings unique to each statement so a test that only cares
 * about storage still gets a coherent digest rendered alongside it.
 */
function digestResponse(string $body, ?string $lastSeen = null): PromiseInterface
{
    /*
     * Matched before the hourly branch: the per-service trend also selects
     * toStartOfInterval, so only its GROUP BY tells the two statements apart.
     */
    if (str_contains($body, 'GROUP BY ServiceName, Bucket')) {
        $hour = now()->utc()->startOfHour();

        // Only `api` logged in the last 24 hours; `worker` has to come back flat.
        return Http::response(
            json_encode([
                'ServiceName' => 'api',
                'Bucket' => $hour->clone()->subHours(5)->format('Y-m-d H:i:s.u'),
                'Total' => '40',
                'Errors' => '4',
            ])."\n"
            .json_encode([
                'ServiceName' => 'api',
                'Bucket' => $hour->format('Y-m-d H:i:s.u'),
                'Total' => '60',
                'Errors' => '9',
            ])."\n",
        );
    }

    if (str_contains($body, 'toStartOfInterval')) {
        /*
         * Two hours of the last 24, deliberately non-adjacent: everything in
         * between has to come back as a filled zero rather than a gap.
         */
        $hour = now()->utc()->startOfHour();

        return Http::response(
            json_encode([
                'Bucket' => $hour->clone()->subHours(5)->format('Y-m-d H:i:s.u'),
                'Total' => '40',
                'Errors' => '4',
            ])."\n"
            .json_encode([
                'Bucket' => $hour->format('Y-m-d H:i:s.u'),
                'Total' => '60',
                'Errors' => '2',
            ])."\n",
        );
    }

    if (str_contains($body, 'countIf')) {
        return Http::response(json_encode([
            'Total' => '150',
            'Current' => '100',
            'ErrorTotal' => '12',
            'ErrorCurrent' => '8',
        ])."\n");
    }

    if (str_contains($body, 'GROUP BY Body')) {
        return Http::response(
            json_encode(['Body' => 'Connection refused', 'Total' => '5'])."\n"
            .json_encode(['Body' => 'Timeout talking to redis', 'Total' => '3'])."\n",
        );
    }

    if (str_contains($body, 'max(Timestamp)')) {
        $fresh = $lastSeen ?? now()->utc()->subMinutes(2)->format('Y-m-d H:i:s.u');

        return Http::response(
            json_encode([
                'ServiceName' => 'worker',
                'LastSeen' => now()->utc()->subHours(6)->format('Y-m-d H:i:s.u'),
            ])."\n"
            .json_encode(['ServiceName' => 'api', 'LastSeen' => $fresh])."\n",
        );
    }

    return Http::response(json_encode(['Present' => 1])."\n");
}

test('dashboard digest summarises the last 24 hours', function () {
    [$user, $team] = storageTeam();

    Http::fake(fn (Request $request) => digestResponse($request->body()));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('digest.unavailable', false)
        ->where('digest.generatedAt', now()->utc()->startOfMinute()->format('Y-m-d H:i:s.u'))
        ->where('digest.logs.current', 100)
        ->where('digest.logs.previous', 50)
        ->where('digest.logs.deltaPercent', 100)
        ->where('digest.errors.current', 8)
        ->where('digest.errors.previous', 4)
        ->where('digest.errors.deltaPercent', 100)
        ->has('digest.topErrors', 2)
        ->where('digest.topErrors.0.body', 'Connection refused')
        ->where('digest.topErrors.0.total', 5)
        ->has('digest.services', 2)
        ->where('digest.services.0.name', 'worker')
        ->where('digest.services.0.quiet', true)
        ->where('digest.services.1.name', 'api')
        ->where('digest.services.1.quiet', false)
        ->has('digest.services.0.series', 24)
        ->has('digest.services.0.errorSeries', 24)
        ->has('digest.services.1.series', 24)
        ->has('digest.services.1.errorSeries', 24)
        ->has('digest.series', 24),
    );

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'countIf')) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($request->body(), 'Timestamp >= {from:DateTime64(9)}')
            && str_contains($request->body(), 'countIf(Timestamp >= {mid:DateTime64(9)})')
            && isset($query['param_projectIds'], $query['param_from'], $query['param_mid']);
    });
});

test('the digest series is a dense 24 hour trend, oldest hour first', function () {
    [$user, $team] = storageTeam();

    Http::fake(fn (Request $request) => digestResponse($request->body()));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();

    $series = $response->viewData('page')['props']['digest']['series'];

    expect($series)->toHaveCount(24);

    $buckets = array_column($series, 'bucket');

    expect($buckets)->toBe(array_values(collect(range(0, 23))
        ->map(fn (int $hour): string => now()
            ->utc()
            ->startOfHour()
            ->subHours(23 - $hour)
            ->format('Y-m-d H:i:s.u'))
        ->all()));

    // The two hours the fake answered, cast to ints.
    expect($series[18])->toBe(['bucket' => $buckets[18], 'total' => 40, 'errors' => 4]);
    expect($series[23])->toBe(['bucket' => $buckets[23], 'total' => 60, 'errors' => 2]);

    // Every other hour is a filled zero, not a missing entry.
    foreach ([0, 5, 17, 19, 22] as $index) {
        expect($series[$index]['total'])->toBe(0)
            ->and($series[$index]['errors'])->toBe(0);
    }

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'toStartOfInterval')) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($request->body(), 'Timestamp >= {from:DateTime64(9)}')
            && str_contains($request->body(), 'Timestamp <= {to:DateTime64(9)}')
            && ! str_contains($request->body(), 'toStartOfInterval(Timestamp, toIntervalHour(1)) >=')
            && str_contains($request->body(), 'GROUP BY Bucket ORDER BY Bucket ASC')
            && isset($query['param_projectIds'], $query['param_from'], $query['param_to']);
    });
});

test('dashboard digest is null for a team with no projects', function () {
    Http::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('digest', null),
    );

    Http::assertNothingSent();
});

test('an overloaded clickhouse marks the digest unavailable without failing the page', function () {
    [$user, $team] = storageTeam();

    Http::fake([
        '127.0.0.1:8123/*' => Http::response('Code: 202. Too many simultaneous queries', 503),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('digest.unavailable', true)
        ->has('digest.generatedAt')
        ->where('digest.logs.current', 0)
        ->where('digest.errors.current', 0)
        ->has('digest.topErrors', 0)
        ->has('digest.services', 0)
        ->has('digest.series', 24)
        ->where('digest.series.0.total', 0),
    );
});

test('dashboard reports the ingest limiter usage of every key in the team', function () {
    [$user, $team, $api, $worker] = storageTeam();

    $apiPlainKey = 'bilis_'.str_repeat('a', 40);

    ProjectApiKey::factory()
        ->forProject($api)
        ->withPlainKey($apiPlainKey)
        ->create(['name' => 'Production collector']);

    ProjectApiKey::factory()
        ->forProject($worker)
        ->withPlainKey('bilis_'.str_repeat('b', 40))
        ->create(['name' => 'Worker collector']);

    config(['security.ingest_rate_limit' => 1200]);

    Http::fake(fn (Request $request) => str_contains($request->body(), 'INSERT')
        ? Http::response('')
        : digestResponse($request->body()));

    // Spend two of the Api key's requests through the real throttle stack.
    $this->withToken($apiPlainKey)->postJson('/api/v1/ingest', ['message' => 'hello'])->assertStatus(202);
    $this->withToken($apiPlainKey)->postJson('/api/v1/ingest', ['message' => 'hello'])->assertStatus(202);

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('ingestRate.limit', 1200)
        ->where('ingestRate.disabled', false)
        ->has('ingestRate.keys', 2)
        ->where('ingestRate.keys.0.project', 'Api')
        ->where('ingestRate.keys.0.projectSlug', 'api')
        ->where('ingestRate.keys.0.name', 'Production collector')
        ->where('ingestRate.keys.0.attempts', 2)
        ->where('ingestRate.keys.0.remaining', 1198)
        ->where('ingestRate.keys.1.name', 'Worker collector')
        ->where('ingestRate.keys.1.attempts', 0)
        ->where('ingestRate.keys.1.remaining', 1200)
        // Only the hash is ever stored, and only the display prefix is shown.
        ->missing('ingestRate.keys.0.keyHash'),
    );
});

test('dashboard ingest usage is null for a team with no projects', function () {
    Http::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('ingestRate', null),
    );
});

test('dashboard ingest usage lists no keys for a team that has not created one', function () {
    [$user, $team] = storageTeam();

    Http::fake(fn (Request $request) => digestResponse($request->body()));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('ingestRate.keys', 0),
    );
});

test('a disabled ingest limiter is reported as disabled rather than as zero usage', function () {
    [$user, $team, $api] = storageTeam();

    ProjectApiKey::factory()
        ->forProject($api)
        ->withPlainKey('bilis_'.str_repeat('c', 40))
        ->create(['name' => 'Production collector']);

    config(['security.ingest_rate_limit' => 0]);

    Http::fake(fn (Request $request) => digestResponse($request->body()));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('ingestRate.disabled', true)
        ->where('ingestRate.limit', 0)
        ->where('ingestRate.keys.0.attempts', 0),
    );
});

test('each service carries its own dense 24 hour trend', function () {
    [$user, $team] = storageTeam();

    Http::fake(fn (Request $request) => digestResponse($request->body()));

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => $team->slug]));

    $response->assertOk();

    $services = collect($response->viewData('page')['props']['digest']['services'])
        ->keyBy('name');

    $api = $services['api']['series'];

    expect($api)->toHaveCount(24);

    // The two hours the fake answered, in order, everything else filled with zeroes.
    expect($api[18])->toBe(40)
        ->and($api[23])->toBe(60)
        ->and(array_sum($api))->toBe(100);

    foreach ([0, 5, 17, 19, 22] as $index) {
        expect($api[$index])->toBe(0);
    }

    $apiErrors = $services['api']['errorSeries'];

    expect($apiErrors)->toHaveCount(24);

    // The error overlay lands on the same hours, dense and zero-filled between them.
    expect($apiErrors[18])->toBe(4)
        ->and($apiErrors[23])->toBe(9)
        ->and(array_sum($apiErrors))->toBe(13);

    foreach ([0, 5, 17, 19, 22] as $index) {
        expect($apiErrors[$index])->toBe(0);
    }

    // Present in the 7 day liveness list, silent for the last 24: a flatline, not a gap.
    expect($services['worker']['series'])->toBe(array_fill(0, 24, 0))
        ->and($services['worker']['errorSeries'])->toBe(array_fill(0, 24, 0));

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->body(), 'GROUP BY ServiceName, Bucket')) {
            return false;
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}')
            && str_contains($request->body(), 'Timestamp >= {from:DateTime64(9)}')
            && str_contains($request->body(), 'Timestamp <= {to:DateTime64(9)}')
            && str_contains($request->body(), 'countIf(SeverityNumber >= {errorSeverity:UInt8}) AS Errors')
            && ! str_contains($request->body(), 'toStartOfInterval(Timestamp, toIntervalHour(1)) >=')
            && isset($query['param_projectIds'], $query['param_from'], $query['param_to'], $query['param_errorSeverity']);
    });
});
