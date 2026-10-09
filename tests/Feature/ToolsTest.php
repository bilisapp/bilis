<?php

use function Pest\Laravel\get;

it('lists every tool on the index', function () {
    get(route('tools.index'))
        ->assertOk()
        ->assertSee(route('tools.log-cost'), false)
        ->assertSee(route('tools.log-levels'), false)
        ->assertSee(route('tools.traceparent'), false)
        ->assertSee(route('tools.timestamp'), false);
});

it('renders every tool without inertia and with structured data', function (string $route) {
    $page = html(get(route($route))->assertOk());

    expect($page)
        ->not->toContain('data-page=')
        ->toContain('application/ld+json')
        ->toContain('"@type":"WebApplication"')
        ->toContain('data-tool-form')
        ->toContain('data-tool-result')
        ->toContain('<link rel="canonical" href="'.route($route).'">');
})->with(['tools.log-cost', 'tools.log-levels', 'tools.traceparent', 'tools.timestamp']);

it('computes the cost estimate on the server from the query string', function () {
    get(route('tools.log-cost', ['eps' => 1000, 'size' => 500, 'ingest' => 0.10, 'index' => 1.70, 'retention' => 15, 'ratio' => 10]))
        ->assertOk()
        ->assertSee('$4,536', false)
        ->assertSee('64.8 GB', false);
});

it('never answers a tool with a validation error', function () {
    get(route('tools.log-cost', ['eps' => 'NaN', 'size' => ['a' => 'b']]))->assertOk();
    get(route('tools.timestamp', ['value' => str_repeat('9', 500), 'unit' => 'fortnights', 'tz' => '../../etc']))->assertOk();
    get(route('tools.traceparent', ['value' => str_repeat('-', 2000)]))->assertOk();
    get(route('tools.log-levels', ['level' => ['x']]))->assertOk();
});

it('looks a level up on the server', function () {
    get(route('tools.log-levels', ['level' => 'critical']))
        ->assertOk()
        ->assertSee('ERROR2 · 18', false);

    get(route('tools.log-levels', ['level' => 'shouting']))
        ->assertOk()
        ->assertSee('data-test="level-none"', false);
});

it('decodes a traceparent on the server', function () {
    get(route('tools.traceparent', ['value' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']))
        ->assertOk()
        ->assertSee('data-test="traceparent-valid"', false)
        ->assertSee('4bf92f3577b34da6a3ce929d0e0e4736');

    get(route('tools.traceparent', ['value' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01']))
        ->assertOk()
        ->assertSee('data-test="traceparent-errors"', false);
});

it('generates a traceparent when asked or when empty', function () {
    get(route('tools.traceparent', ['generate' => 1]))
        ->assertOk()
        ->assertSee('data-test="traceparent-generated"', false);
});

it('converts a timestamp on the server', function () {
    get(route('tools.timestamp', ['value' => '1760020200123456789']))
        ->assertOk()
        ->assertSee('2025-10-09T14:30:00.123456789Z')
        ->assertSee('&quot;timeUnixNano&quot;: &quot;1760020200123456789&quot;', false);

    get(route('tools.timestamp', ['value' => 'yesterday-ish?!']))
        ->assertOk()
        ->assertSee('data-test="timestamp-error"', false);
});

it('points canonical at the bare path, whatever the query string', function () {
    expect(html(get(route('contact.show', ['topic' => 'upgrade']))->assertOk()))
        ->toContain('<link rel="canonical" href="'.route('contact.show').'">');
});

it('lists public pages in the sitemap and nothing private', function () {
    $xml = get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
        ->getContent();

    expect($xml)
        ->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<loc>'.route('home').'</loc>')
        ->toContain('<loc>'.route('tools.timestamp').'</loc>')
        ->toContain('<loc>'.route('docs.show', ['section' => 'getting-started', 'page' => 'quickstart']).'</loc>')
        ->toContain('<loc>'.route('blog.show', ['post' => 'no-protobuf-library']).'</loc>')
        ->not->toContain(route('login'))
        ->not->toContain(route('styleguide'));

    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue();
});

it('points robots.txt at the sitemap', function () {
    get('/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: '.route('sitemap'), false);
});

it('keeps the app and its auth screens out of the index', function () {
    get(route('login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    expect(html(get(route('styleguide'))->assertOk()))->toContain('<meta name="robots" content="noindex, follow">');
    get(route('home'))->assertOk()->assertDontSee('noindex', false);
});
