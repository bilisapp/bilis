<?php

use App\Services\Tools\LogVolumeEstimate;

use function Pest\Laravel\get;

it('renders the datadog comparison as a blade page with faq structured data', function () {
    $page = html(get(route('compare.datadog'))->assertOk());

    expect($page)
        ->not->toContain('data-page=')
        ->toContain('"@type":"FAQPage"')
        ->toContain('<link rel="canonical" href="'.route('compare.datadog').'">')
        ->toContain('Honest limits');
});

it('quotes the same bill the calculator does', function () {
    $page = get(route('compare.datadog'))->assertOk()->getContent();
    $expected = LogVolumeEstimate::fromInput(['eps' => 1000])->costPerMonth();

    expect($page)
        ->toContain('$'.number_format($expected, 0))
        ->toContain(e(route('tools.log-cost', LogVolumeEstimate::fromInput(['eps' => 1000])->toQuery())));
});

it('never calls bilis open source', function () {
    // The licence is FSL-1.1-ALv2 until each release converts. "Open source" appears only in the
    // FAQ question and its answer, each rendered twice: once visibly, once in the FAQPage data.
    $page = get(route('compare.datadog'))->assertOk()->getContent();

    expect(substr_count(strtolower($page), 'open source'))->toBe(4)
        ->and($page)->toContain('we do not call it open source');
});

it('is linked from every public footer and listed in the sitemap', function () {
    expect(get(route('home'))->getContent())->toContain(route('compare.datadog'));
    expect(get(route('sitemap'))->getContent())->toContain('<loc>'.route('compare.datadog').'</loc>');
});

it('keeps the coding-agent post a draft until it is reviewed', function () {
    get(route('blog.show', ['post' => 'production-logs-for-your-coding-agent']))->assertNotFound();
    get(route('blog.index'))->assertOk()->assertDontSee('Give your coding agent your production logs');
});
