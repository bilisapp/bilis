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

test('the hero and the live tail share one shader band', function () {
    $page = $this->get(route('home'))->assertOk()->getContent();

    $band = substr($page, strpos($page, 'data-fold-gradient'));
    $band = substr($band, 0, strpos($band, '</section>'));

    expect($band)->toContain('Your logs and traces, on your own box.')
        ->toContain('data-live-tail-list');
});

test('the landing page loads the marketing bundle but never boots inertia', function () {
    $response = $this->get(route('home'))->assertOk();

    // The tag is either the dev server URL or the hashed build asset; both
    // carry the entry name, and neither may drag the Inertia root along.
    $response->assertSee('marketing', false);
    $response->assertDontSee('data-page=', false);
});

test('marketing pages without a hero load no shader and no stream', function () {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertDontSee('data-fold-gradient', false)
        ->assertDontSee('data-live-tail', false);
});

test('the live tail renders its whole stream server-side', function () {
    $page = $this->get(route('home'))->assertOk()->getContent();

    // Without JavaScript the pane must still read as a full stream: the
    // script only ever adds to something already complete.
    expect(substr_count($page, 'data-live-tail-list'))->toBe(1)
        ->and(substr_count($page, '<time'))->toBe(12)
        ->and($page)->toContain('data-live-tail-pool')
        ->and($page)->toContain('text-severity-fatal');
});

test('the ingest snippet shows the round trip and can be copied', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-copy="ingest-request"', false)
        ->assertSee('id="ingest-request"', false)
        // The counts and status the controller actually answers with.
        ->assertSee('202 Accepted', false)
        ->assertSee('{"accepted":', false);
});

test('every section below the hero is numbered', function () {
    $page = $this->get(route('home'))->assertOk()->getContent();

    foreach (['01', '02', '03', '04', '05'] as $number) {
        expect($page)->toContain(">{$number}</span>");
    }
});
