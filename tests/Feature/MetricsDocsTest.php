<?php

declare(strict_types=1);

use App\Services\Docs\DocsRepository;

use function Pest\Laravel\get;

it('renders the metrics ingestion guide the explorer links to', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'metrics']))->assertOk();

    expect(html($response))
        ->toContain('/api/v1/metrics')
        ->toContain('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT')
        // The Collector's base `endpoint` would append /v1/metrics and miss
        // the /api prefix; the per-signal key is the one that works.
        ->toContain('metrics_endpoint: https://your-bilis-host/api/v1/metrics')
        ->toContain('rejectedDataPoints')
        ->toContain('gRPC is not supported');
});

it('serves the metrics guide as raw markdown', function () {
    $response = get(route('docs.markdown', ['section' => 'ingestion', 'page' => 'metrics']))->assertOk();

    $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    expect($response->getContent())->toStartWith('# Metrics')
        ->toContain('POST /api/v1/metrics')
        ->toContain('metrics_endpoint')
        ->not->toContain('title: Metrics');
});

it('places the metrics guide right after traces', function () {
    $ingestion = collect(app(DocsRepository::class)->sections())->firstWhere('slug', 'ingestion');

    $slugs = array_map(fn ($page) => $page->slug, $ingestion->pages);

    expect(array_slice($slugs, 0, 3))->toBe(['endpoints', 'traces', 'metrics']);
});

it('no longer tells readers that Bilis has no metrics', function (string $section, string $page) {
    $response = get(route('docs.show', ['section' => $section, 'page' => $page]))->assertOk();

    expect(html($response))
        ->not->toContain('No metrics. Logs and traces only.')
        ->not->toContain('Bilis has no metrics')
        ->not->toContain('nowhere to put')
        ->not->toContain('pipeline on purpose');
})->with([
    ['getting-started', 'overview'],
    ['ingestion', 'traces'],
    ['ingestion', 'shippers'],
    ['ingestion', 'claude-code'],
    ['ingestion', 'endpoints'],
]);
