<?php

declare(strict_types=1);

use App\Services\Docs\DocsRepository;

use function Pest\Laravel\get;

it('renders the Linux server agent guide', function () {
    $response = get(route('docs.show', ['section' => 'ingestion', 'page' => 'server-agent']))->assertOk();

    expect(html($response))
        ->toContain('Linux server agent')
        ->toContain('/install.sh')
        ->toContain('BILIS_API_KEY=bilis_YOUR_API_KEY')
        ->toContain('bilis-agent uninstall')
        ->toContain('0600')
        ->toContain('/etc/bilis-agent/agent.env')
        ->toContain('--no-docker')
        ->toContain('0.159.0');
});

it('serves the server agent guide as raw markdown', function () {
    $response = get(route('docs.markdown', ['section' => 'ingestion', 'page' => 'server-agent']))->assertOk();

    expect($response->getContent())->toStartWith('# Linux server agent')
        ->toContain('curl -fsSL https://your-bilis-host/install.sh')
        ->not->toContain('order: 9');
});

it('places the agent right before the manual Linux host recipe', function () {
    $ingestion = collect(app(DocsRepository::class)->sections())->firstWhere('slug', 'ingestion');

    $slugs = array_map(fn ($page) => $page->slug, $ingestion->pages);
    $agent = array_search('server-agent', $slugs, true);

    expect($agent)->not->toBeFalse()
        ->and($slugs[$agent + 1])->toBe('linux-host');
});

it('links the Linux host recipe and the agent to each other', function () {
    $linuxHost = get(route('docs.show', ['section' => 'ingestion', 'page' => 'linux-host']))->assertOk();
    $agent = get(route('docs.show', ['section' => 'ingestion', 'page' => 'server-agent']))->assertOk();

    expect(html($linuxHost))->toContain('href="/docs/ingestion/server-agent"')
        ->and(html($agent))->toContain('href="/docs/ingestion/linux-host"');
});
