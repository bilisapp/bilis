<?php

/**
 * The public surfaces share one header, one footer and one <head>.
 *
 * The landing page, the docs, the blog, the features page and the styleguide
 * are built three different ways — Blade layouts, a Blade docs shell and an
 * Inertia root view — so the thing worth pinning is that a visitor moving
 * between them cannot tell.
 */
$surfaces = [
    'home' => fn () => route('home'),
    'features' => fn () => route('features'),
    'pricing' => fn () => route('pricing'),
    'contact' => fn () => route('contact.show'),
    'blog' => fn () => route('blog.index'),
    'docs' => fn () => route('docs.show', ['section' => 'getting-started', 'page' => 'overview']),
    'styleguide' => fn () => route('styleguide'),
];

test('every public surface renders the shared header', function (string $name) use ($surfaces) {
    $page = $this->get($surfaces[$name]())->assertOk()->getContent();

    expect($page)
        // The animated mark, which only works because the logo is inline SVG.
        ->toContain('mark-live')
        ->toContain('data-mark-stripe')
        // The same four section links, in the same order.
        ->toContain(route('features'))
        ->toContain(route('pricing'))
        ->toContain(route('docs.index'))
        ->toContain(route('blog.index'));
})->with(array_keys($surfaces));

test('every public surface renders the shared footer', function (string $name) use ($surfaces) {
    $page = $this->get($surfaces[$name]())->assertOk()->getContent();

    expect($page)
        // The styleguide is a resource, so it lives in the footer.
        ->toContain(route('styleguide'))
        ->toContain(route('pricing'))
        ->toContain(route('contact.show'))
        ->toContain(route('terms'))
        ->toContain(route('privacy'))
        ->toContain(config('legal.operator.name'));
})->with(array_keys($surfaces));

test('every public surface paints the same ground before its stylesheet', function (string $name) use ($surfaces) {
    // These must equal --background in each mode; see .ai/rules/css.md.
    $this->get($surfaces[$name]())
        ->assertOk()
        ->assertSee('background-color: hsl(225 20% 97%)', false)
        ->assertSee('background-color: hsl(225 14% 8%)', false)
        ->assertSee('content="#f6f7f9"', false)
        ->assertSee('content="#111317"', false);
})->with(array_keys($surfaces));

test('the styleguide is the only public surface that boots inertia', function () use ($surfaces) {
    foreach ($surfaces as $name => $url) {
        $page = $this->get($url())->assertOk()->getContent();

        expect(str_contains($page, 'data-page='))->toBe(
            $name === 'styleguide',
            "{$name} disagreed about booting Inertia",
        );
    }
});
