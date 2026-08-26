<?php

use App\Http\Controllers\Api\LogIngestController;
use App\Http\Controllers\Api\OtlpLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('project.api-key')->prefix('v1')->group(function () {
    Route::post('logs', [OtlpLogController::class, 'store'])->name('api.v1.logs.store');
    Route::post('ingest', [LogIngestController::class, 'store'])->name('api.v1.ingest.store');
});
