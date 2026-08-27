<?php

use App\Http\Controllers\Api\AutofixArtifactController;
use App\Http\Controllers\Api\LogIngestController;
use App\Http\Controllers\Api\OtlpLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['project.api-key', 'throttle:ingest'])->prefix('v1')->group(function () {
    Route::post('logs', [OtlpLogController::class, 'store'])->name('api.v1.logs.store');
    Route::post('ingest', [LogIngestController::class, 'store'])->name('api.v1.ingest.store');
});

/*
 * Ayos calling home. Not a public API and not versioned with one: the only
 * client is the execution service Bilis dispatches to, authenticated by the
 * shared-secret HMAC over the raw body rather than by a project API key.
 */
Route::middleware('ayos.signature')->prefix('internal/autofix')->group(function () {
    Route::post('artifacts', [AutofixArtifactController::class, 'store'])->name('api.internal.autofix.artifacts');
});
