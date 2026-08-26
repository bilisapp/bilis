<?php

use Illuminate\Support\Facades\Route;

test('forwarded https proxy headers are trusted for generated urls', function () {
    Route::get('/proxy-url-test', fn (): string => url('/build/app.css'));

    $this->get('/proxy-url-test', [
        'Host' => 'bilis.app',
        'X-Forwarded-For' => '203.0.113.10',
        'X-Forwarded-Host' => 'bilis.app',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Proto' => 'https',
    ])->assertOk()->assertSeeText('https://bilis.app/build/app.css');
});

test('forwarded headers from an untrusted address are ignored once proxies are named', function () {
    config(['security.trusted_proxies' => '192.0.2.1, 198.51.100.0/24']);

    Route::get('/proxy-url-test', fn (): string => url('/build/app.css'));

    // The request comes from 127.0.0.1 in the test client, which is not on the
    // list, so its claim to be HTTPS on another host carries no weight and the
    // URL falls back to the configured application URL.
    $this->get('/proxy-url-test', [
        'X-Forwarded-Host' => 'evil.example.com',
        'X-Forwarded-Proto' => 'https',
    ])->assertOk()->assertSeeText(config('app.url').'/build/app.css');
});
