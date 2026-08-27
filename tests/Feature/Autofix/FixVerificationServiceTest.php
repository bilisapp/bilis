<?php

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Services\Autofix\ErrorFingerprinter;
use App\Services\Autofix\FixTriggerService;
use App\Services\Autofix\FixVerificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * One error row as `LogQuery` hands it back.
 *
 * @return array<string, mixed>
 */
function verificationRow(string $exception = 'App\\Exceptions\\PaymentFailed', string $service = 'checkout'): array
{
    return [
        'projectId' => '1',
        'timestamp' => '2026-08-27 12:00:00.000000000',
        'traceId' => '',
        'spanId' => '',
        'severityText' => 'ERROR',
        'severityNumber' => 17,
        'serviceName' => $service,
        'body' => implode("\n", [
            $exception.': Charge declined for order 4821',
            '#0 /var/www/app/Services/Billing/Charger.php(118): App\\Services\\Billing\\Gateway->charge(Array)',
            '#1 /var/www/app/Http/Controllers/CheckoutController.php(30): App\\Services\\Billing\\Charger->run()',
        ]),
        'scopeName' => '',
        'scopeVersion' => '',
        'resourceAttributes' => [],
        'logAttributes' => [],
    ];
}

/**
 * The fingerprint one of those rows carries.
 *
 * @param  array<string, mixed>  $row
 */
function verificationFingerprint(array $row): string
{
    return app(ErrorFingerprinter::class)->fingerprint($row);
}

/**
 * A JSONEachRow body of `$count` copies of the given row.
 *
 * @param  array<string, mixed>  $row
 */
function verificationRows(int $count, array $row): string
{
    $lines = [];

    for ($i = 0; $i < $count; $i++) {
        $lines[] = json_encode([
            'ProjectId' => $row['projectId'],
            'Timestamp' => $row['timestamp'],
            'TraceId' => '',
            'SpanId' => '',
            'SeverityText' => $row['severityText'],
            'SeverityNumber' => $row['severityNumber'],
            'ServiceName' => $row['serviceName'],
            'Body' => $row['body'],
            'ScopeName' => '',
            'ScopeVersion' => '',
            'ResourceAttributes' => [],
            'LogAttributes' => [],
        ]);
    }

    return $lines === [] ? '' : implode("\n", $lines)."\n";
}

/**
 * The responses the faked hosts currently answer with.
 *
 * Laravel merges successive `Http::fake()` calls rather than replacing them,
 * so a test that needs a host to answer differently the second time round
 * changes this state instead of re-faking.
 */
function verificationState(): object
{
    static $state = null;

    return $state ??= new class
    {
        public string $clickHouse = '';

        public int $commentStatus = 201;
    };
}

/**
 * Fake ClickHouse and the two GitHub endpoints the verification loop touches.
 */
function fakeVerificationHosts(?string $clickHouseBody = null, ?int $commentStatus = null): void
{
    $state = verificationState();

    if ($clickHouseBody !== null) {
        $state->clickHouse = $clickHouseBody;
    }

    if ($commentStatus !== null) {
        $state->commentStatus = $commentStatus;
    }

    Http::fake([
        '127.0.0.1:8123/*' => fn () => Http::response($state->clickHouse),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_comment']),
        'api.github.com/repos/acme/app/issues/*/comments' => fn () => Http::response(
            $state->commentStatus >= 400 ? ['message' => 'Server Error'] : ['id' => 9001],
            $state->commentStatus,
        ),
    ]);
}

/**
 * A merged fix job whose fingerprint is the one `verificationRow()` produces.
 */
function mergedFixJob(Carbon $mergedAt, array $attributes = []): FixJob
{
    $team = Team::factory()->create(['slug' => 'acme']);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);
    $installation = GitHubInstallation::factory()->create(['team_id' => $team->id, 'installation_id' => 4242]);

    $repository = ProjectRepository::factory()
        ->forProject($project)
        ->forInstallation($installation)
        ->autofixEnabled()
        ->create(['repo_full_name' => 'acme/app', 'default_branch' => 'main']);

    return FixJob::factory()->forRepository($repository)->create([
        'status' => FixJobStatus::Merged,
        'fingerprint' => verificationFingerprint(verificationRow()),
        'error_context' => [
            'exception' => 'App\\Exceptions\\PaymentFailed',
            'message' => 'Charge declined for order 4821',
            'service_name' => 'checkout',
            'count' => 9,
        ],
        'pr_number' => 42,
        'pr_url' => 'https://github.com/acme/app/pull/42',
        'dispatched_at' => $mergedAt->clone()->subHour(),
        'completed_at' => $mergedAt,
        'verified_at' => null,
        'verification' => null,
        ...$attributes,
    ]);
}

/**
 * The bodies of the pull request comments that were posted.
 *
 * @return list<string>
 */
function postedComments(): array
{
    $bodies = [];

    Http::recorded(function (Request $request) use (&$bodies) {
        if (str_contains($request->url(), '/issues/') && str_contains($request->url(), '/comments')) {
            $bodies[] = (string) ($request->data()['body'] ?? '');
        }

        return false;
    });

    return $bodies;
}

beforeEach(function () {
    verificationState()->clickHouse = '';
    verificationState()->commentStatus = 201;

    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
        'autofix.enabled' => true,
        'autofix.defaults.verify_after_hours' => 2,
        'autofix.defaults.verify_fail_after_hours' => 24,
        'autofix.github.app_id' => '123456',
        'autofix.github.private_key' => base64_encode(verificationPrivateKey()),
    ]);
});

/**
 * A throwaway RSA key so the App JWT can actually be signed.
 */
function verificationPrivateKey(): string
{
    static $pem = null;

    if ($pem === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $exported);
        $pem = (string) $exported;
    }

    return $pem;
}

test('a merged fix whose error stopped is verified and commented on once', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(3));

    /*
     * A different error in the same window: it is read by the query and must
     * not count against this fingerprint.
     */
    fakeVerificationHosts(verificationRows(4, verificationRow('App\\Exceptions\\ShippingFailed')));

    $handled = app(FixVerificationService::class)->verify();

    expect($handled)->toHaveCount(1);

    $job->refresh();

    expect($job->verified_at)->not->toBeNull()
        ->and($job->status)->toBe(FixJobStatus::Merged)
        ->and($job->verification['outcome'])->toBe(FixVerificationService::OUTCOME_VERIFIED)
        ->and($job->verification['occurrences'])->toBe(0)
        ->and($job->verification['window']['from'])->toBe('2026-08-27T12:00:00Z')
        ->and($job->verification['window']['to'])->toBe('2026-08-27T15:00:00Z')
        ->and($job->verification['checked_at'])->toBe('2026-08-27T15:00:00Z');

    $comments = postedComments();

    expect($comments)->toHaveCount(1)
        ->and($comments[0])->toContain('✅ **Verified in production.**')
        ->and($comments[0])->toContain('Since this merged 3 hours ago')
        ->and($comments[0])->toContain('has not recurred')
        ->and($comments[0])->toContain(mb_substr($job->fingerprint, 0, 16))
        ->and($comments[0])->toContain('/logs?')
        ->and($comments[0])->toContain('project=checkout');

    Carbon::setTestNow();
});

test('the comment token is scoped to pull request writes and nothing else', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    mergedFixJob($now->clone()->subHours(3));

    fakeVerificationHosts();

    app(FixVerificationService::class)->verify();

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'access_tokens')) {
            return false;
        }

        expect($request->data()['permissions'])->toBe(['pull_requests' => 'write'])
            ->and($request->data()['repositories'])->toBe(['app']);

        return true;
    });

    Carbon::setTestNow();
});

test('a merged fix whose error still recurs is reported as failed after the deadline', function () {
    $now = Carbon::parse('2026-08-28 18:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(30));

    fakeVerificationHosts(verificationRows(6, verificationRow()));

    $handled = app(FixVerificationService::class)->verify();

    expect($handled)->toHaveCount(1);

    $job->refresh();

    expect($job->verified_at)->toBeNull()
        ->and($job->status)->toBe(FixJobStatus::Merged)
        ->and($job->completed_at->toDateTimeString())->toBe('2026-08-27 12:00:00')
        ->and($job->verification['outcome'])->toBe(FixVerificationService::OUTCOME_FAILED)
        ->and($job->verification['occurrences'])->toBe(6);

    $comments = postedComments();

    expect($comments)->toHaveCount(1)
        ->and($comments[0])->toContain('⚠️ **The fix did not take.**')
        ->and($comments[0])->toContain('**6 occurrences**')
        ->and($comments[0])->toContain('30 hours after this merged')
        ->and($comments[0])->toContain('stays eligible for another fix attempt');

    Carbon::setTestNow();
});

test('a fix merged inside the grace window is left alone', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subMinutes(30));

    fakeVerificationHosts();

    expect(app(FixVerificationService::class)->verify())->toBe([]);

    $job->refresh();

    expect($job->verification)->toBeNull()
        ->and($job->verified_at)->toBeNull();

    Http::assertNothingSent();

    Carbon::setTestNow();
});

test('a recurring error before the failure deadline is left for a later pass', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(4));

    fakeVerificationHosts(verificationRows(3, verificationRow()));

    expect(app(FixVerificationService::class)->verify())->toBe([]);

    $job->refresh();

    expect($job->verification)->toBeNull()
        ->and($job->verified_at)->toBeNull()
        ->and(postedComments())->toBe([]);

    Carbon::setTestNow();
});

test('a job that already carries a verdict is never commented on twice', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    mergedFixJob($now->clone()->subHours(3));

    fakeVerificationHosts();

    app(FixVerificationService::class)->verify();
    app(FixVerificationService::class)->verify();

    expect(postedComments())->toHaveCount(1);

    Carbon::setTestNow();
});

test('an unavailable clickhouse skips the pass silently', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(3));

    Http::fake([
        '127.0.0.1:8123/*' => Http::response('too many parts', 503),
        'api.github.com/*' => Http::response(['token' => 'ghs_comment']),
    ]);

    expect(app(FixVerificationService::class)->verify())->toBe([]);

    $job->refresh();

    expect($job->verification)->toBeNull()
        ->and($job->verified_at)->toBeNull()
        ->and(postedComments())->toBe([]);

    Carbon::setTestNow();
});

test('a failed github comment leaves the job for the next pass', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(3));

    fakeVerificationHosts(commentStatus: 500);

    expect(app(FixVerificationService::class)->verify())->toBe([]);

    $job->refresh();

    expect($job->verification)->toBeNull()
        ->and($job->verified_at)->toBeNull();

    fakeVerificationHosts(commentStatus: 201);

    expect(app(FixVerificationService::class)->verify())->toHaveCount(1);

    /*
     * The retry is the point: the first attempt never landed, so the verdict
     * had to be posted again before the job could be marked handled.
     */
    expect($job->refresh()->verified_at)->not->toBeNull()
        ->and($job->verification['outcome'])->toBe(FixVerificationService::OUTCOME_VERIFIED)
        ->and(postedComments())->toHaveCount(1);

    Carbon::setTestNow();
});

test('a job with no pull request number is skipped', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(3), ['pr_number' => null]);

    fakeVerificationHosts();

    expect(app(FixVerificationService::class)->verify())->toBe([]);
    expect($job->refresh()->verification)->toBeNull();

    Http::assertNothingSent();

    Carbon::setTestNow();
});

test('verification does nothing while autofix is disabled', function () {
    config()->set('autofix.enabled', false);

    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    mergedFixJob($now->clone()->subHours(3));

    fakeVerificationHosts();

    expect(app(FixVerificationService::class)->verify())->toBe([]);

    Http::assertNothingSent();

    Carbon::setTestNow();
});

test('the verify command reports the verdicts it recorded', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    mergedFixJob($now->clone()->subHours(3));

    fakeVerificationHosts();

    $this->artisan('autofix:verify')
        ->expectsOutputToContain('App\\Exceptions\\PaymentFailed')
        ->assertSuccessful();

    Carbon::setTestNow();
});

test('a fix whose verification failed is re-triggered when the error is seen again', function () {
    Queue::fake();

    $now = Carbon::parse('2026-08-28 18:00:00');
    Carbon::setTestNow($now);

    $job = mergedFixJob($now->clone()->subHours(30));

    fakeVerificationHosts(verificationRows(6, verificationRow()));

    app(FixVerificationService::class)->verify();

    expect($job->refresh()->verification['outcome'])->toBe(FixVerificationService::OUTCOME_FAILED);

    /*
     * The scan that follows sees the same fingerprint, recorded after the
     * merge: the trigger's regression path, unchanged by verification.
     */
    $recurrence = verificationRow();
    $recurrence['timestamp'] = $now->clone()->subMinutes(5)->format('Y-m-d H:i:s.u').'000';

    fakeVerificationHosts(verificationRows(6, $recurrence));

    $created = app(FixTriggerService::class)->scanRepository($job->repository, $now);

    expect($created)->toHaveCount(1)
        ->and($created[0]->fingerprint)->toBe($job->fingerprint)
        ->and($created[0]->is($job))->toBeFalse();

    Queue::assertPushed(DispatchFixJob::class);

    Carbon::setTestNow();
});

test('a merged custom job is never ruled on', function () {
    $now = Carbon::parse('2026-08-27 15:00:00');
    Carbon::setTestNow($now);

    fakeVerificationHosts(verificationRows(0, verificationRow()));

    $job = mergedFixJob($now->clone()->subHours(30), [
        'type' => FixJobType::Custom,
        'fingerprint' => null,
        'error_context' => null,
        'instructions' => 'Add a /healthz endpoint that returns 204.',
    ]);

    expect(app(FixVerificationService::class)->verify())->toBe([])
        ->and(app(FixVerificationService::class)->verifyJob($job))->toBeNull();

    $job->refresh();

    expect($job->verification)->toBeNull()
        ->and($job->verified_at)->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/comments'));
});
