<?php

declare(strict_types=1);

use App\Http\Controllers\DocsController;
use App\Services\Docs\DocsRepository;
use App\Services\Markdown\FrontMatter;
use App\Services\Markdown\MarkdownRenderer;

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

it('renders the Go guide with both ingest routes', function () {
    get(route('docs.show', ['section' => 'ingestion', 'page' => 'go']))
        ->assertOk()
        ->assertSee('slog handler')
        ->assertSee('otlploghttp')
        ->assertSee('/api/v1/ingest');
});

it('tells the overview truthfully what v1 ships, traces included', function () {
    $response = get(route('docs.show', ['section' => 'getting-started', 'page' => 'overview']))->assertOk();

    $response->assertSee('POST /api/v1/traces')
        ->assertSee('How a span travels')
        ->assertSee('otel_traces')
        ->assertSee('trace_summary')
        ->assertDontSee('No traces and no metrics')
        ->assertDontSee('no OTLP protobuf encoding');
});

it('renders the traces guide with a base endpoint the SDKs can append to and a complete Collector file', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'traces']))->assertOk();

    $html = html($response);

    // The signal-agnostic endpoint ends in /api, so an SDK appending /v1/traces lands on the route.
    expect($html)->toContain('OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api')
        ->not->toContain('OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host\n')
        ->toContain('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://your-bilis-host/api/v1/traces')
        // The Collector file declares everything it references.
        ->toContain('traces_endpoint: https://your-bilis-host/api/v1/traces')
        ->toContain('logs_endpoint: https://your-bilis-host/api/v1/logs')
        ->toContain('extensions: [file_storage]')
        ->toContain('storage: file_storage')
        ->toContain('receivers: [otlp]')
        // One quickstart per SDK the logs docs cover.
        ->toContain('auto-instrumentations-node')
        ->toContain('opentelemetry-instrument')
        ->toContain('otlptracehttp.New')
        ->toContain('opentelemetry-auto-laravel');
});

it('shows the Laravel shipper how to correlate log lines with spans', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'shippers']))->assertOk();

    expect(html($response))->toContain('Correlating logs with traces')
        ->toContain('Span::getCurrent()')
        ->toContain('isValid()')
        ->toContain('AddTraceContext')
        // The Collector example is the same complete file as on the traces page.
        ->toContain('traces_endpoint: https://bilis.example.com/api/v1/traces')
        ->toContain('extensions: [file_storage]');
});

it('says what the envelope endpoint does with transactions and trace contexts', function () {
    get(route('docs.show', ['section' => 'ingestion', 'page' => 'sentry']))
        ->assertOk()
        ->assertSee('contexts.trace')
        ->assertDontSee('Tracing is out of scope');
});

it('documents span retention and the statements that change it', function () {
    $response = get(route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']))->assertOk();

    expect(html($response))->toContain('Changing retention')
        ->toContain('materialize_ttl_after_modify = 0')
        ->toContain('ALTER TABLE otel_logs')
        ->toContain('ALTER TABLE otel_traces')
        ->toContain('ALTER TABLE trace_summary')
        ->toContain('toDateTime(Start) + toIntervalDay(90)');
});

it('renders the Claude Code guide with the three defaults that send nothing', function () {
    get(route('docs.show', ['section' => 'ingestion', 'page' => 'claude-code']))
        ->assertOk()
        ->assertSee('CLAUDE_CODE_ENABLE_TELEMETRY')
        ->assertSee('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT')
        // The per-signal endpoints matter because Bilis serves /api/v1, not /v1.
        ->assertSee('/api/v1/traces')
        ->assertSee('OTEL_METRICS_EXPORTER');
});

it('offers the page as a prompt, carrying the key placeholder, wherever a key is needed', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'claude-code']))->assertOk();

    $html = html($response);

    expect($html)->toContain('Copy as a prompt')
        // The prompt points at the raw markdown rather than repeating the guide.
        ->toContain(route('docs.markdown', ['section' => 'ingestion', 'page' => 'claude-code']))
        // The placeholder is what the API key panel rewrites in place.
        ->toContain(DocsController::API_KEY_PLACEHOLDER)
        ->toContain('data-docs-api-key-target')
        ->toContain(rtrim(url('/'), '/'));
});

it('leaves the prompt block off a page that needs no API key', function () {
    get(route('docs.show', ['section' => 'ingestion', 'page' => 'severity']))
        ->assertOk()
        ->assertDontSee('Copy as a prompt');
});

it('serves a page as raw markdown, without its front matter', function () {
    $response = get(route('docs.markdown', ['section' => 'ingestion', 'page' => 'severity']))->assertOk();

    $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    $body = $response->getContent();

    expect($body)->toStartWith('# Severity')
        ->toContain('Source: '.route('docs.show', ['section' => 'ingestion', 'page' => 'severity']))
        ->toContain('## The levels')
        ->not->toContain('order: 3')
        ->not->toContain('title: Severity');
});

it('404s for a markdown request for an unknown page', function () {
    get('/docs/ingestion/nope.md')->assertNotFound();
});

it('offers the page as markdown from the rendered page', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'severity']))
        ->assertOk()
        ->assertSee('Copy as Markdown');

    expect(html($response))
        ->toContain('rel="alternate" type="text/markdown"')
        ->toContain(route('docs.markdown', ['section' => 'ingestion', 'page' => 'severity']));
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
        'ingestion' => ['endpoints', 'traces', 'api-keys', 'timestamps', 'severity', 'shippers', 'go', 'linux-host', 'sentry', 'claude-code'],
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
    $rendered = app(MarkdownRenderer::class)->render(<<<'MD'
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
    $rendered = app(MarkdownRenderer::class)->render("Hello <script>alert(1)</script>\n");

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
