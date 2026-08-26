<?php

declare(strict_types=1);

use App\Services\Docs\DocsRenderer;
use App\Services\Docs\DocsRepository;
use App\Services\Docs\FrontMatter;

use function Pest\Laravel\get;

it('sends /docs to the first page of the first section', function () {
    get(route('docs.index'))
        ->assertRedirect(route('docs.show', ['section' => 'getting-started', 'page' => 'overview']));
});

it('renders a documentation page to a logged-out visitor', function () {
    get(route('docs.show', ['section' => 'ingestion', 'page' => 'endpoints']))
        ->assertOk()
        ->assertSee('Endpoints')
        ->assertSee('POST /api/v1/logs')
        ->assertSee('The never-400 contract');
});

it('renders the sidebar nav and the on-this-page list around the content', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'severity']))->assertOk();

    $response->assertSee('aria-label="Documentation"', false)
        ->assertSee('docs-prose', false)
        ->assertSee('On this page')
        ->assertSee('Getting started')
        ->assertSee('Reference');

    // The current page is marked, and its siblings are linked.
    $response->assertSee('aria-current="page"', false)
        ->assertSee(route('docs.show', ['section' => 'ingestion', 'page' => 'timestamps']), false);
});

it('404s for an unknown page or an unknown section', function () {
    get('/docs/ingestion/nope')->assertNotFound();
    get('/docs/nope/endpoints')->assertNotFound();
});

it('never leaks the section metadata files into the nav', function () {
    get(route('docs.show', ['section' => 'getting-started', 'page' => 'overview']))
        ->assertOk()
        ->assertDontSee('_section');

    get('/docs/getting-started/_section')->assertNotFound();
});

it('lists every section and page in front matter order', function () {
    $sections = app(DocsRepository::class)->sections();

    expect(array_map(fn ($section) => $section->slug, $sections))
        ->toBe(['getting-started', 'ingestion', 'reference']);

    $pages = [];

    foreach ($sections as $section) {
        $pages[$section->slug] = array_map(fn ($page) => $page->slug, $section->pages);
    }

    expect($pages)->toBe([
        'getting-started' => ['overview', 'quickstart'],
        'ingestion' => ['endpoints', 'timestamps', 'severity', 'shippers'],
        'reference' => ['limits-and-behavior'],
    ]);
});

it('parses a front matter block into attributes and a body', function () {
    $parsed = FrontMatter::parse("---\ntitle: Overview\norder: 2\n---\n\n# Hello\n");

    expect($parsed['attributes'])->toBe(['title' => 'Overview', 'order' => '2'])
        ->and($parsed['body'])->toBe("# Hello\n");
});

it('leaves a document without front matter untouched', function () {
    expect(FrontMatter::parse("# Hello\n"))
        ->toBe(['attributes' => [], 'body' => "# Hello\n"]);
});

it('renders fenced code, tables and heading anchors', function () {
    $rendered = app(DocsRenderer::class)->render(<<<'MD'
        ## Send a line

        ```bash
        curl -X POST https://bilis.test/api/v1/ingest
        ```

        | Level | Number |
        | --- | --- |
        | info | 9 |
        MD);

    expect($rendered->html)
        ->toContain('<pre><code class="language-bash">')
        ->toContain('curl -X POST')
        ->toContain('<table>')
        ->toContain('id="send-a-line"')
        ->toContain('class="docs-anchor"');

    expect($rendered->tableOfContents)
        ->toBe([['id' => 'send-a-line', 'title' => 'Send a line', 'level' => 2]]);
});

it('strips raw html out of the rendered markdown', function () {
    $rendered = app(DocsRenderer::class)->render("Hello <script>alert(1)</script>\n");

    expect($rendered->html)->not->toContain('<script>');
});

it('keeps the docs free of the inertia bundle', function () {
    $content = get(route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']))->getContent();

    expect($content)->not->toContain('data-page=')
        ->and($content)->not->toContain('resources/js/app.ts');
});

it('links the docs from the marketing pages', function () {
    get(route('home'))
        ->assertOk()
        ->assertSee(route('docs.index'), false);
});
