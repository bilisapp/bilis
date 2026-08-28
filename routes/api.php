<?php

use App\Http\Controllers\Api\AutofixArtifactController;
use App\Http\Controllers\Api\AutofixEventController;
use App\Http\Controllers\Api\LogIngestController;
use App\Http\Controllers\Api\OtlpLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.api-key', 'throttle:ingest'])->prefix('v1')->group(function () {
    Route::post('logs', [OtlpLogController::class, 'store'])->name('api.v1.logs.store');
    Route::post('ingest', [LogIngestController::class, 'store'])->name('api.v1.ingest.store');
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
