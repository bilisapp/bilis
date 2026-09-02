<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders the pricing page to a logged-out visitor', function () {
    get(route('pricing'))
        ->assertOk()
        ->assertSee('<title>Pricing — '.config('app.name').'</title>', false)
        ->assertSee('Free on bilis.app. Free on your own box.');
});

it('publishes the configured Free plan numbers rather than literals', function () {
    // The page's whole job is being the published source of these six
    // numbers; if a config override does not move them, the page is lying.
    config([
        'plans.free.projects_per_team' => 7,
        'plans.free.members_per_team' => 11,
        'plans.free.events_per_day' => 2_500_000,
        'plans.warn_at_percent' => 65,
        'legal.log_retention_days' => 45,
        'security.ingest_rate_limit' => 900,
    ]);

    get(route('pricing'))
        ->assertOk()
        ->assertSee('7 projects')
        ->assertSee('11 members')
        ->assertSee('2,500,000 events a day')
        ->assertSee('45-day retention')
        ->assertSee('900 requests a minute')
        ->assertSee('65%');
});

it('says the limits are soft and that nothing is purchasable', function () {
    $response = get(route('pricing'))->assertOk();

    $response->assertSee('These limits are soft.')
        ->assertSee('Self-hosting stays first-class')
        ->assertSee('No price list yet.')
        // Nothing on this page may read as a checkout, and no allowance may
        // be described with a word the product cannot honour.
        ->assertDontSee('unlimited', false)
        ->assertDontSee('Unlimited', false);
});

it('sends a team that wants more room to the contact form', function () {
    get(route('pricing'))
        ->assertOk()
        ->assertSee(route('contact.show', ['topic' => 'upgrade']), false)
        ->assertSee(route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']), false);
});

it('carries the social card for the pricing page', function () {
    $title = 'Pricing — '.config('app.name');

    $response = get(route('pricing'))->assertOk();

    expect(html($response))
        ->toContain('<meta property="og:url" content="'.route('pricing').'">')
        ->toContain('<meta property="og:title" content="'.$title.'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

it('stays Blade only and never boots inertia', function () {
    get(route('pricing'))
        ->assertOk()
        ->assertDontSee('data-page=', false);
});

it('marks pricing as the current item in the public header', function () {
    $page = get(route('pricing'))->assertOk()->getContent();

    // The header renders its links twice — the wide row and the scrolling
    // mobile row — and both must agree on where the reader is.
    expect(substr_count($page, 'aria-current="page"'))->toBe(2);
});
