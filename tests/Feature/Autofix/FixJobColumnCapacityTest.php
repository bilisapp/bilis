<?php

use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Services\Autofix\AyosClient;
use App\Services\Autofix\AyosException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The columns this application deliberately writes long values into.
 *
 * This file exists because of a production-only failure that the whole test
 * suite was structurally unable to see. `fix_jobs.failure_reason` was
 * `varchar(255)` while every writer truncated to 1000. SQLite — which is what
 * development and this suite run on — does not enforce a `varchar` length at
 * all, so the mismatch was invisible; Postgres does, so the first long failure
 * reason in production came back as `SQLSTATE[22001] value too long`.
 *
 * A round-trip test cannot catch that: on SQLite it passes whatever the
 * declared length is. So these assert the declared TYPE instead, which SQLite
 * does report faithfully, and which is the thing Postgres actually enforces.
 */
test('every column written with unbounded text is a text column', function (string $column) {
    $type = collect(Schema::getColumns('fix_jobs'))->firstWhere('name', $column)['type'] ?? null;

    expect($type)->toBe('text');
})->with([
    // Quotes an upstream response body. Capped at MAX_FAILURE_REASON, which is
    // four times what a varchar(255) would hold.
    'failure_reason',
    // A person's typed request, up to CreateFixJobRequest::MAX_LENGTH (10000).
    'instructions',
    // A whole patch.
    'diff',
]);

/*
 * The regression itself, phrased the way it actually happened: an upstream 403
 * whose JSON body pushes the message past 255 characters. It reached the
 * failure handler, so the job could not even record why it failed — the write
 * threw, the queue retried it eight times, and the row stayed as it was.
 */
test('a dispatch failure quoting a long upstream body is recorded, not thrown away', function () {
    $job = ayosJob();

    $body = json_encode([
        'details' => [
            ['action' => 'read', 'resource' => 'api_namespace'],
            ['action' => 'read', 'resource' => 'registry_image'],
        ],
        'message' => 'insufficient permissions',
        'type' => 'permissions_denied',
    ]);

    $reason = sprintf(
        'Ayos answered job-definitions/%s/start with status 403: %s',
        '8feea0f4-9b81-4632-8534-f6a80068423a',
        $body,
    );

    expect(mb_strlen($reason))->toBeGreaterThan(255);

    $this->mock(AyosClient::class)
        ->shouldReceive('dispatch')
        ->andThrow(new AyosException($reason, statusCode: 403));

    try {
        (new DispatchFixJob($job->uuid))->handle(app(AyosClient::class));
    } catch (Throwable) {
        // `fail()` rethrows outside a real queue worker; the recorded state is
        // what this is about.
    }

    $job->refresh();

    expect($job->failure_reason)->toBe($reason)
        ->and($job->status->value)->toBe('failed');
});

/*
 * The reason is stored whole. A truncated one stops being useful at exactly
 * the moment it is needed — the 403 that made this column a problem carried
 * the missing IAM permissions in its body.
 */
test('a long reason is stored in full rather than clipped', function () {
    $job = ayosJob();

    $reason = 'Ayos answered with status 403: '.str_repeat('detail. ', 400);

    expect(mb_strlen($reason))->toBeGreaterThan(1000);

    $this->mock(AyosClient::class)
        ->shouldReceive('dispatch')
        ->andThrow(new AyosException($reason, statusCode: 403));

    try {
        (new DispatchFixJob($job->uuid))->handle(app(AyosClient::class));
    } catch (Throwable) {
        // As above.
    }

    expect($job->refresh()->failure_reason)->toBe($reason);
});

/*
 * The valve is not a length limit, it is protection from a proxy returning
 * megabytes of HTML onto every failed row.
 */
test('a pathological reason is cut at the safety valve', function () {
    $job = ayosJob();

    $this->mock(AyosClient::class)
        ->shouldReceive('dispatch')
        ->andThrow(new AyosException(str_repeat('a', FixJob::MAX_FAILURE_REASON + 500), statusCode: 400));

    try {
        (new DispatchFixJob($job->uuid))->handle(app(AyosClient::class));
    } catch (Throwable) {
        // As above.
    }

    expect(mb_strlen((string) $job->refresh()->failure_reason))->toBe(FixJob::MAX_FAILURE_REASON);
});

/*
 * Whatever the row keeps, the log keeps everything: the message the operator
 * reads AND the upstream body in full, which is where a control plane says
 * which permission or quota actually blocked the run.
 */
test('the full upstream body reaches the log even when the row does not carry it', function () {
    $job = ayosJob();

    $body = json_encode([
        'details' => [['action' => 'read', 'resource' => 'registry_image']],
        'message' => 'insufficient permissions',
        'type' => 'permissions_denied',
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($body, $job): bool {
            return $context['fix_job'] === $job->uuid
                && $context['response_body'] === $body;
        });

    $this->mock(AyosClient::class)
        ->shouldReceive('dispatch')
        ->andThrow(new AyosException('Ayos answered with status 403.', statusCode: 403, responseBody: $body));

    try {
        (new DispatchFixJob($job->uuid))->handle(app(AyosClient::class));
    } catch (Throwable) {
        // As above.
    }
});

test('the exception carries the upstream body untruncated, however long', function () {
    $body = str_repeat('x', AyosException::MESSAGE_BODY_LIMIT * 3);

    $exception = AyosException::fromResponse(
        new Illuminate\Http\Client\Response(new Response(403, [], $body)),
        'job-definitions/abc/start',
    );

    // The message is bounded so the UI stays readable; the body is not.
    expect(mb_strlen((string) $exception->responseBody()))->toBe(mb_strlen($body))
        ->and(mb_strlen($exception->getMessage()))
        ->toBeLessThan(AyosException::MESSAGE_BODY_LIMIT + 200);
});
