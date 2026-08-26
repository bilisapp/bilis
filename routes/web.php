<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\ProjectApiKeyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StyleguideController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'marketing.home')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('logs', [LogsController::class, 'index'])->name('logs.index');
        Route::get('logs/tail', [LogsController::class, 'tail'])->name('logs.tail');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::post('projects/{project}/api-keys', [ProjectApiKeyController::class, 'store'])->name('projects.api-keys.store');
        Route::delete('projects/{project}/api-keys/{apiKey}', [ProjectApiKeyController::class, 'destroy'])->name('projects.api-keys.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('styleguide', StyleguideController::class)->name('styleguide');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
