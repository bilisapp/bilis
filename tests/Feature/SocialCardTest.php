<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders a complete social card on a marketing page', function () {
    $response = get(route('home'))->assertOk();

    expect(html($response))->toContain('<meta property="og:type" content="website">')
        ->toContain('<meta property="og:site_name" content="'.config('app.name').'">')
        ->toContain('<meta property="og:url" content="'.route('home').'">')
        ->toContain('<meta property="og:title" content="'.config('app.name').' — self-hosted logs and traces">')
        ->toContain('<meta property="og:image" content="'.asset('og.png').'">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta property="og:image:alt"')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<meta name="twitter:title" content="'.config('app.name').' — self-hosted logs and traces">')
        ->toContain('<meta name="twitter:image" content="'.asset('og.png').'">');
});

it('renders a complete social card on a documentation page', function () {
    $url = route('docs.show', ['section' => 'ingestion', 'page' => 'endpoints']);

    $response = get($url)->assertOk();

    expect(html($response))->toContain('<meta property="og:type" content="website">')
        ->toContain('<meta property="og:url" content="'.$url.'">')
        ->toContain('<meta property="og:image" content="'.asset('og.png').'">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<meta name="twitter:image" content="'.asset('og.png').'">');
});

it('gives the card the same title and description the page already carries', function () {
    // One set of strings, not two: the card must never drift from the <title>.
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'endpoints']))->assertOk();

    $title = 'Endpoints — '.config('app.name').' docs';

    expect(html($response))->toContain('<title>'.$title.'</title>')
        ->toContain('<meta property="og:title" content="'.$title.'">')
        ->toContain('<meta name="twitter:title" content="'.$title.'">');
});

it('states the card URLs absolutely, as every crawler requires', function () {
    $response = get(route('home'))->assertOk();

    foreach (['og:url', 'og:image', 'twitter:image'] as $property) {
        $attribute = str_starts_with($property, 'og:') ? 'property' : 'name';

        expect(html($response))
            ->toMatch('/<meta '.$attribute.'="'.preg_quote($property, '/').'" content="https?:\/\/[^"]+">/');
    }
});

it('ships the card image at the size the tags advertise', function () {
    $path = public_path('og.png');

    expect($path)->toBeFile();

    [$width, $height] = getimagesize($path);

    expect($width)->toBe(1200)
        ->and($height)->toBe(630)
        ->and(filesize($path))->toBeLessThan(400 * 1024);
});

it('animates the mark in the marketing header and leaves the footer still', function () {
    $page = get(route('home'))->assertOk()->getContent();

    $header = substr($page, strpos($page, '<header'), strpos($page, '</header>') - strpos($page, '<header'));

    // Inline SVG, because CSS cannot reach the stripes inside an <img>.
    expect($header)->toContain('mark-live')
        ->toContain('data-mark-stripe="1"')
        ->toContain('data-mark-stripe="2"')
        ->toContain('data-mark-stripe="3"')
        ->not->toContain('logo-mark.svg')
        ->not->toContain('logo-icon.svg');

    $footer = substr($page, strpos($page, '<footer'));

    expect($footer)->toContain('/logo-mark.svg')
        ->not->toContain('mark-live');
});

it('keeps the inline mark out of the accessibility tree', function () {
    $page = get(route('home'))->assertOk()->getContent();

    $header = substr($page, strpos($page, '<header'), strpos($page, '</header>') - strpos($page, '<header'));

    expect($header)->toContain('aria-hidden="true"')
        ->toContain('focusable="false"');
});
