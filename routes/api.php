<?php

use App\Http\Controllers\Api\AutofixArtifactController;
use App\Http\Controllers\Api\AutofixEventController;
use App\Http\Controllers\Api\EnvelopeIngestController;
use App\Http\Controllers\Api\LogIngestController;
use App\Http\Controllers\Api\OtlpLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.api-key', 'throttle:ingest'])->prefix('v1')->group(function () {
    Route::post('logs', [OtlpLogController::class, 'store'])->name('api.v1.logs.store');
    Route::post('ingest', [LogIngestController::class, 'store'])->name('api.v1.ingest.store');
});

/*
 * Ingest for error reporting clients configured with a DSN.
 *
 * These paths are the wire protocol's, not ours: a client builds them from its
 * DSN and has no setting that would point it at `/api/v1`. The `{dsnProjectId}`
 * segment is the DSN's own project id and is ignored — the project is the one
 * the public key belongs to (SCHEMA.md R2) — but it has to be matched, and it
 * is pinned to digits so these routes can never shadow a versioned one.
 */
Route::middleware(['envelope.cors', 'project.public-key', 'throttle:ingest'])->group(function () {
    Route::post('{dsnProjectId}/envelope', [EnvelopeIngestController::class, 'envelope'])
        ->whereNumber('dsnProjectId')
        ->name('api.envelope.store');

    Route::post('{dsnProjectId}/store', [EnvelopeIngestController::class, 'store'])
        ->whereNumber('dsnProjectId')
        ->name('api.events.store');
});

/*
 * The preflight a browser sends before the POST above. It carries no
 * credentials and no body — only the key in the query string, which is why it
 * runs without the key middleware and without the throttle: a preflight the
 * limiter rejected would fail the request it is asking permission for.
 */
Route::middleware('envelope.cors')->group(function () {
    Route::options('{dsnProjectId}/{ingestPath}', [EnvelopeIngestController::class, 'preflight'])
        ->whereNumber('dsnProjectId')
        ->whereIn('ingestPath', ['envelope', 'store'])
        ->name('api.envelope.preflight');
});

/*
 * Ayos calling home. Not a public API and not versioned with one: the only
 * clients are the container runs Bilis starts, each authenticated by an
 * Ed25519 signature made with the key minted for that one run rather than by a
 * project API key or a secret shared between the two services.
 *
 * Both routes are inbound-only by necessity. A run has no HTTP surface of its
 * own, so everything it has to say, it says here.
 */
Route::middleware('ayos.signature')->prefix('internal/autofix')->group(function () {
    Route::post('artifacts', [AutofixArtifactController::class, 'store'])->name('api.internal.autofix.artifacts');
    Route::post('events', [AutofixEventController::class, 'store'])->name('api.internal.autofix.events');
});
