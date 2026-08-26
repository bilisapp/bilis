<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('styleguide'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the styleguide', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('styleguide'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('styleguide/Index'));
});
