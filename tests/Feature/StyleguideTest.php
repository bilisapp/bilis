<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests can visit the styleguide', function () {
    // The one public Inertia surface: a live gallery of the app's own Vue
    // components cannot be Blade, so it is reachable logged out.
    $response = $this->get(route('styleguide'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('styleguide/Index'));
});

test('the styleguide wears the shared public chrome server side', function () {
    // The chrome must be in the HTML before the Inertia bundle boots, which
    // is only true while the response uses the styleguide root view.
    $html = $this->get(route('styleguide'))->getContent();

    // Header: the wordmark linking home, and the shared public nav.
    expect($html)
        ->toContain('href="'.route('home').'"')
        ->toContain(route('features'))
        ->toContain(route('docs.index'))
        ->toContain(route('blog.index'))
        // Footer: the operator block only the public footer renders.
        ->toContain(config('legal.operator.name'))
        ->toContain(route('privacy'))
        ->toContain(route('terms'))
        // And the Inertia mount point, still.
        ->toContain('id="app"');
});

test('authenticated users can visit the styleguide', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('styleguide'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('styleguide/Index'));
});
