<?php

use App\Http\Controllers\Auth\GitHubLoginController;
use App\Http\Controllers\Autofix\FixJobCancelController;
use App\Http\Controllers\Autofix\FixJobStreamController;
use App\Http\Controllers\Autofix\FixJobStreamTokenController;
use App\Http\Controllers\Autofix\LogFixJobController;
use App\Http\Controllers\AutofixController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsApiKeyController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\ProjectApiKeyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectRepositoryController;
use App\Http\Controllers\Settings\GitHubInstallationController;
use App\Http\Controllers\StyleguideController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

/*
 * The GitHub App's webhook. It lives here rather than in `routes/api.php`
 * because GitHub is configured with one absolute URL per App and the spec
 * pins it at `/webhooks/github`, which the `api` prefix would move. It is
 * unauthenticated apart from `github.signature`, and CSRF-exempt in
 * `bootstrap/app.php` for the same reason.
 */
Route::post('webhooks/github', GitHubWebhookController::class)
    ->middleware('github.signature')
    ->name('webhooks.github');

Route::view('/', 'marketing.home')->name('home');
Route::view('terms', 'marketing.terms')->name('terms');
Route::view('privacy', 'marketing.privacy')->name('privacy');

/**
 * Public documentation, rendered from `resources/docs/{section}/{page}.md`.
 * Blade only — these pages must read without JavaScript and be indexable.
 */
Route::get('docs', [DocsController::class, 'index'])->name('docs.index');

/*
 * The raw markdown of a page, for the "Copy as Markdown" button and for
 * anything reading the docs without a browser. Declared before `docs.show` so
 * the `.md` suffix wins over a page slug ending in those characters.
 */
Route::get('docs/{section}/{page}.md', [DocsController::class, 'markdown'])
    ->where(['section' => '[A-Za-z0-9-]+', 'page' => '[A-Za-z0-9-]+'])
    ->name('docs.markdown');

Route::get('docs/{section}/{page}', [DocsController::class, 'show'])->name('docs.show');

/*
 * Issue a project API key from a documentation page, so the placeholders in
 * the code samples can be filled in without leaving the page.
 */
Route::post('docs/api-key', DocsApiKeyController::class)
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('docs.api-key');

/**
 * Machine-readable pointer to the disclosure policy, per RFC 9116. Rendered
 * rather than served as a static file so Expires can never go stale.
 */
Route::get('.well-known/security.txt', function () {
    $lines = [
        'Contact: mailto:'.config('legal.contact.security'),
        'Expires: '.now()->addYear()->startOfDay()->toIso8601ZuluString(),
        'Preferred-Languages: en',
        'Canonical: '.url('/.well-known/security.txt'),
        'Policy: '.config('bilis.github_url').'/blob/main/SECURITY.md',
    ];

    return response(implode(PHP_EOL, $lines).PHP_EOL)
        ->header('Content-Type', 'text/plain; charset=utf-8');
})->name('security-txt');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('logs', [LogsController::class, 'index'])->name('logs.index');
        Route::get('logs/tail', [LogsController::class, 'tail'])->name('logs.tail');
        Route::get('logs/older', [LogsController::class, 'older'])->name('logs.older');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::post('projects/{project}/api-keys', [ProjectApiKeyController::class, 'store'])->name('projects.api-keys.store');
        Route::delete('projects/{project}/api-keys/{apiKey}', [ProjectApiKeyController::class, 'destroy'])->name('projects.api-keys.destroy');

        /*
         * The repository one project's autofix attempts may touch. `available`
         * is a live GitHub call answered as JSON, so a slow or broken GitHub
         * degrades the picker instead of the settings page around it.
         */
        Route::get('projects/{project}/repository/available', [ProjectRepositoryController::class, 'available'])->name('projects.repository.available');
        Route::post('projects/{project}/repository', [ProjectRepositoryController::class, 'store'])->name('projects.repository.store');
        Route::patch('projects/{project}/repositories/{repository}', [ProjectRepositoryController::class, 'update'])->name('projects.repository.update');
        Route::delete('projects/{project}/repositories/{repository}', [ProjectRepositoryController::class, 'destroy'])->name('projects.repository.destroy');

        /*
         * Autofix. `{fixJob}` is bound through its project's team in
         * `AppServiceProvider`, so a job from another team is a 404 before any
         * policy runs; `FixJobPolicy` then answers the same question from the
         * model rather than from the URL.
         */
        Route::get('autofix', [AutofixController::class, 'index'])->name('autofix.index');

        /*
         * A job spawned by a person rather than by the scan. It sits above the
         * `{fixJob}` routes because `jobs` would otherwise be read as a uuid,
         * and it is throttled: an agent run is expensive, and the budgets that
         * bound it are per repository rather than per person.
         */
        Route::post('autofix/jobs', [AutofixController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('autofix.store');

        /*
         * A job raised from one log line in the viewer. Sits alongside
         * `jobs` and above `{fixJob}` for the same reason, and carries the
         * same throttle: it spends an agent run, and the repository is
         * derived from the line's service rather than named by the browser.
         */
        Route::post('autofix/from-log', LogFixJobController::class)
            ->middleware('throttle:20,1')
            ->name('autofix.from-log');

        Route::get('autofix/{fixJob}', [AutofixController::class, 'show'])->name('autofix.show');
        Route::post('autofix/{fixJob}/stream-token', FixJobStreamTokenController::class)
            ->middleware('throttle:30,1')
            ->name('autofix.stream-token');
        Route::post('autofix/{fixJob}/cancel', FixJobCancelController::class)->name('autofix.cancel');

        /*
         * The live transcript. This used to be an Ayos endpoint the browser
         * reached across an origin; a container run has nothing listening, so
         * the run POSTs its events to Bilis and this streams them back out of
         * the row we already persist them in.
         */
        Route::get('autofix/{fixJob}/stream', FixJobStreamController::class)->name('autofix.stream');

        /*
         * Start of the GitHub App install round trip. The other half — the
         * App's Setup URL — cannot live under the team prefix, because GitHub
         * holds exactly one absolute URL for the whole App.
         */
        Route::get('settings/github/connect', [GitHubInstallationController::class, 'connect'])->name('github.installations.connect');
    });

/*
 * The GitHub App's Setup URL. GitHub sends every team here with no team in the
 * path, so the team is carried in the signed `state` blob the connect route
 * minted — see `App\Services\Autofix\GitHubInstallState`.
 */
Route::get('settings/github/setup', [GitHubInstallationController::class, 'setup'])
    ->middleware(['auth', 'verified'])
    ->name('github.installations.setup');

/*
 * "Continue with GitHub". The callback links the GitHub identity to an
 * existing account by its verified primary email, or registers a new one with
 * its personal team, and is the only place `github_id` is ever written.
 */
Route::middleware('guest')->group(function () {
    Route::get('auth/github/redirect', [GitHubLoginController::class, 'redirect'])->name('github.redirect');
    Route::get('auth/github/callback', [GitHubLoginController::class, 'callback'])->name('github.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::get('styleguide', StyleguideController::class)->name('styleguide');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
