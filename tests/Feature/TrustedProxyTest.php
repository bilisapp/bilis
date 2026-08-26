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
