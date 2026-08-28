<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;

test('horizon dashboard access is restricted to configured emails', function () {
    config(['horizon.allowed_emails' => ['admin@example.com']]);

    $allowedUser = User::factory()->make(['email' => 'Admin@example.com']);
    $deniedUser = User::factory()->make(['email' => 'other@example.com']);

    expect(Gate::forUser($allowedUser)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($deniedUser)->allows('viewHorizon'))->toBeFalse();
});

test('horizon snapshot is scheduled for metrics', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('horizon:snapshot')
        ->assertSuccessful();
});

test('the horizon dashboard renders under the content security policy', function () {
    // Assert what production serves — see SecurityHeadersTest.
    Vite::useHotFile(storage_path('framework/testing/not-a-hot-file'));

    config(['horizon.allowed_emails' => ['admin@example.com']]);

    $user = User::factory()->create(['email' => 'admin@example.com']);

    $response = $this->actingAs($user)->get('/horizon')->assertOk();

    $nonce = Vite::cspNonce();
    $html = $response->getContent();

    expect($nonce)->not->toBe('')
        // Horizon's bundle is one inline module script; without the nonce
        // `strict-dynamic` blocks it and the dashboard renders empty.
        ->and($html)->toContain('<script type="module" nonce="'.$nonce.'"')
        // The published layout drops the third-party webfont `style-src` blocks.
        ->and($html)->not->toContain('fonts.bunny.net')
        ->and((string) $response->headers->get('Content-Security-Policy'))
        ->toContain("'nonce-{$nonce}'");
});
