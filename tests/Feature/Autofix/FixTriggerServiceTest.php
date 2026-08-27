<?php

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Services\Autofix\ErrorFingerprinter;
use App\Services\Autofix\FixTriggerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A stack trace for one recognisable error.
 */
function triggerStack(string $exception = 'App\\Exceptions\\PaymentFailed'): string
{
    return implode("\n", [
        $exception.': Charge declined for order 4821',
        '#0 /var/www/app/Services/Billing/Charger.php(118): App\\Services\\Billing\\Gateway->charge(Array)',
        '#1 /var/www/app/Http/Controllers/CheckoutController.php(30): App\\Services\\Billing\\Charger->run()',
    ]);
}

/**
 * A JSONEachRow body of `$count` identical error rows.
 */
function triggerRows(int $count, string $body, string $service = 'checkout', string $timestamp = '2026-08-27 09:59:00.000000000'): string
{
    $lines = [];

    for ($i = 0; $i < $count; $i++) {
        $lines[] = json_encode([
            'ProjectId' => '1',
            'Timestamp' => $timestamp,
            'TraceId' => '',
            'SpanId' => '',
            'SeverityText' => 'ERROR',
            'SeverityNumber' => 17,
            'ServiceName' => $service,
            'Body' => $body,
            'ScopeName' => '',
            'ScopeVersion' => '',
            'ResourceAttributes' => [],
            'LogAttributes' => [],
        ]);
    }

    return implode("\n", $lines)."\n";
}

/**
 * Fake ClickHouse with the given JSONEachRow body.
 */
function fakeClickHouse(string $body): void
{
    Http::fake(['127.0.0.1:8123/*' => Http::response($body)]);
}

/**
 * A repository that has opted into autofix.
 */
function autofixRepository(array $attributes = []): ProjectRepository
{
    $project = Project::factory()->create();

    return ProjectRepository::factory()
        ->forProject($project)
        ->autofixEnabled()
        ->create($attributes);
}

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'clickhouse.database' => 'bilis',
        'autofix.enabled' => true,
        'autofix.defaults.min_error_count' => 5,
        'autofix.defaults.cooldown_days' => 7,
    ]);

    Queue::fake();
});

test('an unseen fingerprint above the threshold raises a fix job', function () {
    fakeClickHouse(triggerRows(6, triggerStack()));

    $repository = autofixRepository();

    $created = app(FixTriggerService::class)->scan();

    expect($created)->toHaveCount(1);

    $job = FixJob::query()->sole();

    expect($job->status)->toBe(FixJobStatus::Pending)
        ->and($job->project_id)->toBe($repository->project_id)
        ->and($job->project_repository_id)->toBe($repository->id)
        ->and($job->fingerprint)->toBe(
            app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()])
        )
        ->and($job->error_context['exception'])->toBe('App\\Exceptions\\PaymentFailed')
        ->and($job->error_context['count'])->toBe(6)
        ->and($job->error_context['samples'])->toHaveCount(FixTriggerService::SAMPLES_PER_GROUP)
        ->and($job->error_context['first_seen'])->toBe('2026-08-27 09:59:00.000000000');

    Queue::assertPushed(DispatchFixJob::class, fn (DispatchFixJob $queued): bool => $queued->uuid === $job->uuid);
});

test('an error below the minimum count is left alone', function () {
    fakeClickHouse(triggerRows(4, triggerStack()));

    autofixRepository();

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    expect(FixJob::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a repository that has not opted in is never scanned', function () {
    fakeClickHouse(triggerRows(20, triggerStack()));

    ProjectRepository::factory()->create();

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    Http::assertNothingSent();
});

test('nothing is scanned while autofix is disabled', function () {
    config(['autofix.enabled' => false]);
    fakeClickHouse(triggerRows(20, triggerStack()));

    autofixRepository();

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    Http::assertNothingSent();
});

test('a fingerprint with a job still in flight is skipped', function () {
    fakeClickHouse(triggerRows(20, triggerStack()));

    $repository = autofixRepository(['max_concurrent' => 5]);
    $fingerprint = app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()]);

    FixJob::factory()->forRepository($repository)->running()->create(['fingerprint' => $fingerprint]);

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    expect(FixJob::query()->count())->toBe(1);
});

test('a fingerprint rejected inside the cooldown is skipped', function () {
    fakeClickHouse(triggerRows(20, triggerStack()));

    $repository = autofixRepository();
    $fingerprint = app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()]);

    FixJob::factory()->forRepository($repository)->rejected()->create([
        'fingerprint' => $fingerprint,
        'completed_at' => now()->subDays(2),
    ]);

    expect(app(FixTriggerService::class)->scan())->toBe([]);
});

test('a fingerprint rejected before the cooldown expired is attempted again', function () {
    fakeClickHouse(triggerRows(20, triggerStack()));

    $repository = autofixRepository();
    $fingerprint = app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()]);

    FixJob::factory()->forRepository($repository)->rejected()->create([
        'fingerprint' => $fingerprint,
        'completed_at' => now()->subDays(30),
    ]);

    expect(app(FixTriggerService::class)->scan())->toHaveCount(1);
});

test('a merged fix that regressed is attempted again', function () {
    fakeClickHouse(triggerRows(20, triggerStack(), timestamp: Carbon::now()->utc()->format('Y-m-d H:i:s.u').'000'));

    $repository = autofixRepository();
    $fingerprint = app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()]);

    FixJob::factory()->forRepository($repository)->merged()->create([
        'fingerprint' => $fingerprint,
        'completed_at' => now()->subDays(3),
    ]);

    expect(app(FixTriggerService::class)->scan())->toHaveCount(1);
});

test('a merged fix whose errors all predate the merge is left alone', function () {
    fakeClickHouse(triggerRows(20, triggerStack(), timestamp: Carbon::now()->subMinutes(30)->utc()->format('Y-m-d H:i:s.u').'000'));

    $repository = autofixRepository();
    $fingerprint = app(ErrorFingerprinter::class)->fingerprint(['serviceName' => 'checkout', 'body' => triggerStack()]);

    FixJob::factory()->forRepository($repository)->merged()->create([
        'fingerprint' => $fingerprint,
        'completed_at' => now()->subMinutes(5),
    ]);

    expect(app(FixTriggerService::class)->scan())->toBe([]);
});

test('the concurrency budget caps how many jobs are in flight', function () {
    fakeClickHouse(triggerRows(6, triggerStack('App\\Exceptions\\PaymentFailed'))
        .triggerRows(6, triggerStack('App\\Exceptions\\CardExpired')));

    autofixRepository(['max_concurrent' => 1]);

    expect(app(FixTriggerService::class)->scan())->toHaveCount(1);
});

test('the daily budget caps how many jobs are raised in a day', function () {
    fakeClickHouse(triggerRows(6, triggerStack('App\\Exceptions\\PaymentFailed'))
        .triggerRows(6, triggerStack('App\\Exceptions\\CardExpired')));

    $repository = autofixRepository(['max_concurrent' => 10, 'daily_budget' => 2]);

    FixJob::factory()->forRepository($repository)->merged()->create(['created_at' => now()->subHour()]);

    expect(app(FixTriggerService::class)->scan())->toHaveCount(1);
});

test('an exhausted daily budget skips the scan entirely', function () {
    fakeClickHouse(triggerRows(20, triggerStack()));

    $repository = autofixRepository(['daily_budget' => 1]);

    FixJob::factory()->forRepository($repository)->merged()->create(['created_at' => now()->subHour()]);

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    Http::assertNothingSent();
});

test('an unavailable clickhouse skips the pass instead of undercounting', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('too many parts', 503)]);

    autofixRepository();

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    expect(FixJob::query()->count())->toBe(0);
});

test('the scan command reports when nothing qualifies', function () {
    fakeClickHouse(triggerRows(1, triggerStack()));

    autofixRepository();

    $this->artisan('autofix:scan')
        ->expectsOutputToContain('No errors qualified')
        ->assertSuccessful();
});

test('the scan command does nothing while autofix is disabled', function () {
    config(['autofix.enabled' => false]);
    Http::fake();

    $this->artisan('autofix:scan')
        ->expectsOutputToContain('Autofix is disabled')
        ->assertSuccessful();
});

test('a custom job in flight consumes a concurrency slot the scan wanted', function () {
    fakeClickHouse(triggerRows(6, triggerStack()));

    $repository = autofixRepository(['max_concurrent' => 1]);

    FixJob::factory()->forRepository($repository)->custom()->running()->create();

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    Http::assertNothingSent();
});

test('a custom job raised today spends the daily budget the scan draws from', function () {
    fakeClickHouse(triggerRows(6, triggerStack()));

    $repository = autofixRepository(['max_concurrent' => 10, 'daily_budget' => 1]);

    FixJob::factory()->forRepository($repository)->custom()->merged()->create(['created_at' => now()->subHour()]);

    expect(app(FixTriggerService::class)->scan())->toBe([]);
    Http::assertNothingSent();
});

test('a settled custom job never puts a fingerprint into cooldown', function () {
    fakeClickHouse(triggerRows(6, triggerStack()));

    $repository = autofixRepository(['max_concurrent' => 10, 'daily_budget' => 10]);

    /*
     * A custom job has no fingerprint at all. It must not be mistaken for the
     * latest attempt at the error being scanned for, whatever SQL makes of a
     * null comparison.
     */
    FixJob::factory()->forRepository($repository)->custom()->rejected()->create([
        'completed_at' => now()->subMinutes(5),
    ]);

    $created = app(FixTriggerService::class)->scan();

    expect($created)->toHaveCount(1)
        ->and($created[0]->type)->toBe(FixJobType::Error)
        ->and($created[0]->fingerprint)->not->toBeNull();
});

test('a raised job is typed as an error', function () {
    fakeClickHouse(triggerRows(6, triggerStack()));

    autofixRepository();

    $created = app(FixTriggerService::class)->scan();

    expect($created[0]->type)->toBe(FixJobType::Error)
        ->and($created[0]->instructions)->toBeNull();
});
