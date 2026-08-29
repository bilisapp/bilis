<?php

test('the landing hero carries the shader surface and its palette', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-fold-gradient', false)
        ->assertSee('data-colors="#0d1420,#1f3a5f,#45bfa6,#f3c440,#f3f0e7"', false);
});

test('each ground carries its own palette', function () {
    // Light is authored, not derived: the dark stops inverted are a grey
    // mess, so the light ground gets its own set and the entry picks one.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-light-colors="#e4eaf3,#a9c6e2,#66c3ad,#dcb85e,#4a6c94"', false)
        ->assertSee('data-light-bg-color="#f6f7f9"', false);
});

test('the pre-paint ground matches the background token', function () {
    // The inline style paints before the stylesheet lands; if it disagrees
    // with --background the shader band opens on the wrong ground.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('background-color: hsl(225 20% 97%)', false)
        ->assertSee('background-color: hsl(225 14% 8%)', false);
});

test('the hero and the viewer share one shader band', function () {
    $page = $this->get(route('home'))->assertOk()->getContent();

    $band = substr($page, strpos($page, 'data-fold-gradient'));
    $band = substr($band, 0, strpos($band, '</section>'));

    expect($band)->toContain('Your logs, on your own box.')
        ->toContain('/screenshot-logs-dark.png');
});

test('the landing page loads the hero shader but never boots inertia', function () {
    $response = $this->get(route('home'))->assertOk();

    // The tag is either the dev server URL or the hashed build asset; both
    // carry the entry name, and neither may drag the Inertia root along.
    $response->assertSee('hero-shader', false);
    $response->assertDontSee('data-page=', false);
});

test('marketing pages without a hero load no shader', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertDontSee('data-fold-gradient', false)
        ->assertDontSee('hero-shader', false);
});
