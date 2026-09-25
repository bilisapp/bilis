<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders the terms of service to a logged-out visitor', function () {
    get(route('terms'))
        ->assertOk()
        ->assertSee('Terms of Service')
        ->assertSee(config('legal.operator.name'))
        ->assertSee('Limitation of liability');
});

it('renders the privacy policy to a logged-out visitor', function () {
    get(route('privacy'))
        ->assertOk()
        ->assertSee('Privacy Policy')
        ->assertSee(config('legal.jurisdiction.supervisory_authority'))
        ->assertSee('OVH SAS');
});

it('states the configured log retention period on both documents', function () {
    $days = (string) config('legal.log_retention_days');

    get(route('terms'))->assertSee($days.' days');
    get(route('privacy'))->assertSee($days.' days');
});

it('sends the terms\' plan-limits clause to the pricing page', function () {
    // The terms promise the limits are "published on the site"; that promise
    // is only true while the sentence links to the page that publishes them.
    get(route('terms'))
        ->assertOk()
        ->assertSee(route('pricing'), false)
        ->assertSee('pricing page');
});

it('links the legal pages from the marketing footer', function () {
    get(route('home'))
        ->assertOk()
        ->assertSee(route('terms'), false)
        ->assertSee(route('privacy'), false);
});

it('serves a security.txt pointing at the disclosure policy', function () {
    get('/.well-known/security.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('Contact: mailto:'.config('legal.contact.security'))
        ->assertSee('Policy: '.config('bilis.github_url').'/blob/main/SECURITY.md');
});

it('keeps the marketing pages free of the inertia bundle', function () {
    foreach ([route('terms'), route('privacy')] as $url) {
        $content = get($url)->getContent();

        expect($content)->not->toContain('data-page=')
            ->and($content)->not->toContain('resources/js/app.ts');
    }
});

test('the trace summary retention promised on the legal pages is the one the tables keep', function () {
    $days = (int) config('legal.trace_summary_retention_days');

    foreach (['0003_create_trace_summary_table.sql', '0007_create_trace_index_table.sql'] as $file) {
        expect((string) file_get_contents(database_path("clickhouse/{$file}")))->toContain("toIntervalDay({$days})");
    }

    $this->get(route('privacy'))->assertOk()->assertSee('Trace summaries')->assertSee("{$days} days from ingest", false);
    $this->get(route('terms'))->assertOk()->assertSee("kept for {$days} days", false);
});
