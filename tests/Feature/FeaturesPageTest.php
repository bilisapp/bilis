<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders the features page to a logged-out visitor', function () {
    get(route('features'))
        ->assertOk()
        ->assertSee('<title>Features — '.config('app.name').'</title>', false)
        ->assertSee('Everything Bilis does, and nothing it does not.');
});

it('carries the social card for the features page', function () {
    $title = 'Features — '.config('app.name');

    $response = get(route('features'))->assertOk();

    expect(html($response))
        ->toContain('<meta property="og:url" content="'.route('features').'">')
        ->toContain('<meta property="og:title" content="'.$title.'">')
        ->toContain('<meta name="twitter:title" content="'.$title.'">')
        ->toContain('<meta property="og:image" content="'.asset('og.png').'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

it('marks the features item as the current one in the public header', function () {
    $page = get(route('features'))->assertOk()->getContent();

    // The header renders the links twice — the wide row and the scrolling
    // mobile row — and both must agree on where the reader is.
    expect(substr_count($page, 'aria-current="page"'))->toBe(2);

    $link = strstr($page, 'href="'.route('features').'"');

    expect(substr($link, 0, 200))->toContain('aria-current="page"');
});

it('states the four ingest endpoints and their success codes', function () {
    // The page's whole job is being specific; a rewrite that drops the
    // contract has removed the reason to read it.
    get(route('features'))
        ->assertOk()
        ->assertSee('/api/v1/logs', false)
        ->assertSee('/api/v1/traces', false)
        ->assertSee('/api/v1/ingest', false)
        ->assertSee('/api/{id}/envelope', false)
        ->assertSee('OTLP ExportLogsServiceRequest, JSON or protobuf')
        ->assertSee('OTLP ExportTraceServiceRequest, JSON or protobuf')
        ->assertSee('Ingest never returns 400 for a bad payload');
});

it('keeps the storage and limits claims that make the page honest', function () {
    get(route('features'))
        ->assertOk()
        ->assertSee('ORDER BY (ProjectId, Timestamp, ServiceName)')
        ->assertSee('An acknowledgement is not durability')
        ->assertSee('there is no hosted tier, nothing to buy')
        ->assertSee('On the')
        ->assertSee('way')
        ->assertSee('Out on')
        ->assertSee('purpose');
});

it('stays Blade only and never boots inertia', function () {
    $response = get(route('features'))->assertOk();

    // The marketing bundle is loaded for the copy buttons; the Inertia root
    // must never follow it onto a public page.
    $response->assertSee('marketing', false)
        ->assertDontSee('data-page=', false)
        ->assertDontSee('data-fold-gradient', false);
});
