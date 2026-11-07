<?php

use App\Services\Tools\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;

/**
 * Every host resolves to a public documentation address unless a test says
 * otherwise, so no test ever reaches real DNS or the network.
 *
 * @param  array<string, list<string>>  $answers
 */
function resolveHosts(array $answers = []): void
{
    app()->instance(HostResolver::class, new class($answers) extends HostResolver
    {
        /** @param array<string, list<string>> $answers */
        public function __construct(private array $answers) {}

        public function resolve(string $host): array
        {
            return $this->answers[$host] ?? (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.215.14']);
        }
    });
}

beforeEach(function () {
    resolveHosts();
    Http::preventStrayRequests();
});

it('shows what is in the html of a server-rendered page', function () {
    Http::fake(['example.com/*' => Http::response(<<<'HTML'
        <!doctype html>
        <html lang="en">
        <head>
            <title>Pricing — Example</title>
            <meta name="description" content="Plans for every team.">
            <link rel="canonical" href="https://example.com/pricing">
            <meta property="og:title" content="Pricing">
            <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":"WebPage"}]}</script>
        </head>
        <body>
            <h1>Simple pricing</h1>
            <p>Every plan includes logs, traces and metrics, stored on your own box and read by people and agents alike.</p>
            <ul><li>Free while you try it</li><li>No per-gigabyte bill</li></ul>
            <img src="/a.png" alt="A chart"><img src="/b.png">
            <a href="/docs">Docs</a> <a href="https://other.example/">Elsewhere</a> <a href="#">Menu</a>
            <script>window.x = 1</script>
        </body>
        </html>
        HTML, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

    get(route('tools.crawler-view', ['url' => 'example.com/pricing']))
        ->assertOk()
        ->assertSee('data-test="crawler-verdict-server"', false)
        ->assertSee('Simple pricing')
        ->assertSee('No per-gigabyte bill')
        ->assertSee('[image: A chart]')
        ->assertSee('Organization, WebPage')
        ->assertSee('1 of 2 images have no alt attribute.')
        ->assertSee('1 links have no real href', false)
        ->assertDontSee('window.x');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/pricing'
        && str_contains($request->header('User-Agent')[0], 'Googlebot'));
});

it('calls out a page that only renders with javascript', function () {
    Http::fake(['*' => Http::response(
        '<!doctype html><html><head><title>App</title></head><body><div id="root"></div><noscript>You need to enable JavaScript to run this app.</noscript><script src="/main.js"></script></body></html>',
    )]);

    get(route('tools.crawler-view', ['url' => 'https://spa.example/']))
        ->assertOk()
        ->assertSee('data-test="crawler-verdict-client"', false)
        ->assertSee('You need to enable JavaScript to run this app.')
        ->assertSee('Almost no text arrives without JavaScript.');
});

it('reads a short page with a script as thin, not as an app', function () {
    Http::fake(['*' => Http::response(
        '<!doctype html><html lang="en"><head><title>Example Domain</title></head><body><p>This domain is for use in documentation examples.</p><script src="/s.js"></script></body></html>',
    )]);

    get(route('tools.crawler-view', ['url' => 'https://example.com/']))
        ->assertOk()
        ->assertSee('data-test="crawler-verdict-thin"', false)
        ->assertDontSee('Almost no text arrives without JavaScript.');
});

it('follows redirects by hand and sends the chosen agent', function () {
    Http::fake([
        'http://example.com/' => Http::response('', 301, ['Location' => 'https://example.com/']),
        'https://example.com/' => Http::response('<html><body><p>Home</p></body></html>'),
    ]);

    get(route('tools.crawler-view', ['url' => 'http://example.com/', 'agent' => 'claudebot']))
        ->assertOk()
        ->assertSee('301 http://example.com/ →', false);

    Http::assertSent(fn (Request $request): bool => str_contains($request->header('User-Agent')[0], 'ClaudeBot'));
});

it('refuses addresses inside private or reserved networks', function (string $url) {
    resolveHosts(['internal.example' => ['10.0.0.5'], 'rebind.example' => ['93.184.215.14', '127.0.0.1']]);

    get(route('tools.crawler-view', ['url' => $url]))
        ->assertOk()
        ->assertSee('data-test="crawler-error"', false);

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'ipv6 loopback' => 'http://[::1]/',
    'metadata' => 'http://169.254.169.254/latest/meta-data/',
    'cgnat' => 'http://100.64.0.1/',
    'private dns' => 'https://internal.example/',
    'any private answer' => 'https://rebind.example/',
    'odd port' => 'https://example.com:8080/',
    'scheme' => 'file:///etc/passwd',
    'credentials' => 'https://user:pass@example.com/',
]);

it('refuses a redirect into a private network', function () {
    Http::fake(['https://example.com/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    get(route('tools.crawler-view', ['url' => 'https://example.com/']))
        ->assertOk()
        ->assertSee('It redirects somewhere that is not fetched', false);

    Http::assertSentCount(1);
});

it('caches a fetch and meters uncached ones without a 429', function () {
    Http::fake(['*' => Http::response('<html><body><p>Hi</p></body></html>')]);

    get(route('tools.crawler-view', ['url' => 'https://example.com/']))->assertOk();
    get(route('tools.crawler-view', ['url' => 'https://example.com/']))->assertOk();
    Http::assertSentCount(1);

    foreach (range(1, 10) as $attempt) {
        RateLimiter::hit('tools:crawler-view:127.0.0.1');
    }

    get(route('tools.crawler-view', ['url' => 'https://example.com/other']))
        ->assertOk()
        ->assertSee('That is a lot of fetches', false);
});

it('never answers with a validation error', function () {
    Http::fake(['*' => Http::response('')]);

    get(route('tools.crawler-view', ['url' => ['x'], 'agent' => ['y']]))->assertOk();
    get(route('tools.crawler-view', ['url' => str_repeat('a', 5000)]))->assertOk();
    get(route('tools.crawler-view', ['url' => 'http://']))->assertOk()->assertSee('data-test="crawler-error"', false);
});
