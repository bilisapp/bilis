<?php

use App\Services\Autofix\AyosException;
use App\Services\Autofix\RunStatus;
use App\Services\Autofix\ScalewayRunDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The wire contract with Scaleway's Serverless Jobs API.
 *
 * Pinned deliberately and in detail, because every one of these paths was wrong
 * on the first attempt and none of them failed loudly: an outdated API version
 * or a `/run` that should be `/start` comes back as a 404, which is exactly
 * what a deleted job definition looks like. These assertions are checked
 * against the published OpenAPI schema for v1alpha2.
 */
beforeEach(function () {
    config([
        'autofix.runner.scaleway.api_url' => 'https://api.scaleway.com',
        'autofix.runner.scaleway.region' => 'fr-par',
        'autofix.runner.scaleway.secret_key' => 'scw-secret',
        'autofix.runner.scaleway.job_definition_id' => 'def-1',
    ]);
});

test('starting a run POSTs to job-definitions/{id}/start on v1alpha2', function () {
    Http::fake(['api.scaleway.com/*' => Http::response(['id' => 'run-abc'])]);

    $runId = (new ScalewayRunDriver)->start('{"job_id":"j1"}', 'j1');

    expect($runId)->toBe('run-abc');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe(
            'https://api.scaleway.com/serverless-jobs/v1alpha2/regions/fr-par/job-definitions/def-1/start',
        );
        expect($request->method())->toBe('POST');
        expect($request->header('X-Auth-Token')[0])->toBe('scw-secret');

        return true;
    });
});

/*
 * `POST …/start` has no secret channel — `environment_variables` is a plain
 * string map and the only per-run input there is. Sending anything else is
 * silently ignored, which would have meant a run booting with no spec at all.
 */
test('the spec travels as a plain environment_variables map', function () {
    Http::fake(['api.scaleway.com/*' => Http::response(['id' => 'run-abc'])]);

    (new ScalewayRunDriver)->start('{"job_id":"j1"}', 'j1');

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->toBe([
            'environment_variables' => ['AYOS_JOB_SPEC' => '{"job_id":"j1"}'],
        ]);

        return true;
    });
});

test('a start that returns no id is a failure rather than a phantom run', function () {
    Http::fake(['api.scaleway.com/*' => Http::response(['no' => 'id'])]);

    expect(fn () => (new ScalewayRunDriver)->start('{}', 'j1'))
        ->toThrow(AyosException::class, 'returned no id');
});

test('stopping a run POSTs to job-runs/{id}/stop', function () {
    Http::fake(['api.scaleway.com/*' => Http::response([])]);

    (new ScalewayRunDriver)->stop('run-abc');

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://api.scaleway.com/serverless-jobs/v1alpha2/regions/fr-par/job-runs/run-abc/stop');
});

test('stopping a run that no longer exists is not an error', function () {
    Http::fake(['api.scaleway.com/*' => Http::response('gone', 404)]);

    (new ScalewayRunDriver)->stop('run-abc');
})->throwsNoExceptions();

test('stopping still surfaces a real failure', function () {
    Http::fake(['api.scaleway.com/*' => Http::response('boom', 500)]);

    (new ScalewayRunDriver)->stop('run-abc');
})->throws(AyosException::class);

test('reading a run GETs job-runs/{id}', function () {
    Http::fake(['api.scaleway.com/*' => Http::response(['state' => 'running'])]);

    expect((new ScalewayRunDriver)->status('run-abc'))->toBe(RunStatus::Running);

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://api.scaleway.com/serverless-jobs/v1alpha2/regions/fr-par/job-runs/run-abc');
});

/*
 * The full state enum from the v1alpha2 schema. The classification that matters
 * is Finished, because that is what lets the reaper declare a job lost without
 * waiting for its deadline — calling a live run Finished fails a healthy job.
 */
test('every documented run state is classified', function (string $state, RunStatus $expected) {
    Http::fake(['api.scaleway.com/*' => Http::response(['state' => $state])]);

    expect((new ScalewayRunDriver)->status('run-abc'))->toBe($expected);
})->with([
    ['unknown_state', RunStatus::Running],
    ['initialized', RunStatus::Queued],
    ['validated', RunStatus::Queued],
    ['queued', RunStatus::Queued],
    ['running', RunStatus::Running],
    ['retrying', RunStatus::Running],
    ['interrupting', RunStatus::Running],
    ['succeeded', RunStatus::Finished],
    ['failed', RunStatus::Finished],
    ['interrupted', RunStatus::Finished],
]);

/*
 * Fail OPEN, not closed. A state Scaleway adds after this was written must not
 * cause every in-flight job to be reconciled as dead; the reaper's deadline
 * still catches anything genuinely stuck.
 */
test('an unrecognised state is treated as still alive', function () {
    Http::fake(['api.scaleway.com/*' => Http::response(['state' => 'something_new'])]);

    expect((new ScalewayRunDriver)->status('run-abc'))->toBe(RunStatus::Running);
});

test('a run Scaleway has forgotten is finished', function () {
    Http::fake(['api.scaleway.com/*' => Http::response('not found', 404)]);

    expect((new ScalewayRunDriver)->status('run-abc'))->toBe(RunStatus::Finished);
});

/*
 * A 5xx is NOT an answer. Returning Finished here would let one Scaleway blip
 * fail every job in flight.
 */
test('a platform failure while reading status raises rather than answering', function () {
    Http::fake(['api.scaleway.com/*' => Http::response('boom', 503)]);

    (new ScalewayRunDriver)->status('run-abc');
})->throws(AyosException::class);

test('missing configuration is named', function (string $key) {
    config(["autofix.runner.scaleway.{$key}" => null]);

    Http::fake();

    expect(fn () => (new ScalewayRunDriver)->start('{}', 'j1'))
        ->toThrow(AyosException::class, $key);
})->with(['secret_key', 'job_definition_id']);
