<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

beforeEach(function () {
    // Assert what production serves. A running Vite dev server binds to an IPv6
    // literal here, which no CSP host-source can name, so the policy stands
    // down for it — see SecurityHeaders::devServerIsUnexpressible().
    Vite::useHotFile(storage_path('framework/testing/not-a-hot-file'));
});

/**
 * Pull one directive out of the policy on a response.
 */
function directive(TestResponse $response, string $name): string
{
    $policy = (string) $response->headers->get('Content-Security-Policy');

    foreach (explode(';', $policy) as $part) {
        $part = trim($part);

        if (str_starts_with($part, $name.' ') || $part === $name) {
            return $part;
        }
    }

    return '';
}

it('sends the hardening headers on a public page', function () {
    $response = get(route('home'))->assertOk();

    $response
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

    expect($response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        // Passkey sign-in needs the credential APIs; nothing else does.
        ->toContain('publickey-credentials-get=(self)');
});

it('locks down the dangerous directives', function () {
    $response = get(route('home'))->assertOk();

    expect(directive($response, 'object-src'))->toBe("object-src 'none'")
        ->and(directive($response, 'frame-ancestors'))->toBe("frame-ancestors 'none'")
        ->and(directive($response, 'base-uri'))->toBe("base-uri 'self'")
        ->and(directive($response, 'form-action'))->toBe("form-action 'self'")
        ->and(directive($response, 'default-src'))->toBe("default-src 'self'");
});

it('never allows inline or eval-ed script', function () {
    $script = directive(get(route('home'))->assertOk(), 'script-src');

    expect($script)->toContain("'strict-dynamic'")
        ->and($script)->not->toContain("'unsafe-inline'")
        ->and($script)->not->toContain("'unsafe-eval'");
});

it('stamps every inline tag with the nonce from the policy', function () {
    $response = get(route('home'))->assertOk();

    preg_match("/'nonce-([^']+)'/", directive($response, 'script-src'), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $content = (string) $response->getContent();
    $nonce = $matches[1];

    // Every script tag the page emits — inline or sourced — must carry it, or
    // `strict-dynamic` would simply break the page.
    preg_match_all('/<script\b[^>]*>/', $content, $tags);

    expect($tags[0])->not->toBeEmpty();

    foreach ($tags[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce.'"');
    }
});

it('issues a fresh nonce per request', function () {
    $first = directive(get(route('home'))->assertOk(), 'script-src');
    $second = directive(get(route('home'))->assertOk(), 'script-src');

    expect($first)->not->toBe($second);
});

it('allows the inline styles Vue writes for positioning', function () {
    $style = directive(get(route('home'))->assertOk(), 'style-src');

    expect($style)->toContain("'unsafe-inline'");
});

it('allows the configured analytics origin to load and report', function () {
    config(['bilis.analytics.script_url' => 'https://analytics.example.com/script.js']);

    $response = get(route('home'))->assertOk();

    expect(directive($response, 'script-src'))->toContain('https://analytics.example.com')
        ->and(directive($response, 'connect-src'))->toContain('https://analytics.example.com');
});

it('emits no analytics script and allows no third party when analytics is off', function () {
    config(['bilis.analytics.script_url' => '']);

    $response = get(route('home'))->assertOk();

    expect($response->getContent())->not->toContain('script.js')
        ->and(directive($response, 'script-src'))->not->toContain('analytics.example.com')
        ->and(directive($response, 'connect-src'))->not->toContain('analytics.example.com');
});

it('can report instead of enforce', function () {
    config(['security.csp.report_only' => true, 'security.csp.report_uri' => 'https://csp.example.com/report']);

    $response = get(route('home'))->assertOk();

    $response->assertHeaderMissing('Content-Security-Policy');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain('report-uri https://csp.example.com/report');
});

it('can be turned off entirely', function () {
    config(['security.csp.enabled' => false]);

    get(route('home'))->assertOk()
        ->assertHeaderMissing('Content-Security-Policy')
        // The rest of the hardening is not part of that switch.
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sends hsts only over a secure request', function () {
    get(route('home'))->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://bilis.app'.route('home', absolute: false), ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('sends no policy on a json api response but still sends the headers', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $plainTextKey = 'bilis_'.str_repeat('c', 40);
    $project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($project)->withPlainKey($plainTextKey)->create();

    $this->withToken($plainTextKey)
        ->postJson('/api/v1/ingest', ['message' => 'hello'])
        ->assertStatus(202)
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('nonces every script in the built app shell, not just the public pages', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    preg_match("/'nonce-([^']+)'/", directive($response, 'script-src'), $matches);

    $content = (string) $response->getContent();

    preg_match_all('/<script\b[^>]*>/', $content, $tags);

    // Inertia's page payload is `type="application/json"` — a data block the
    // browser never executes, and therefore never checks against the policy.
    // Every tag that does run has to carry the nonce.
    $executable = array_values(array_filter(
        $tags[0],
        fn (string $tag): bool => ! str_contains($tag, 'type="application/json"'),
    ));

    expect($executable)->not->toBeEmpty();

    foreach ($executable as $tag) {
        expect($tag)->toContain('nonce="'.$matches[1].'"');
    }

    // `strict-dynamic` only holds if the module graph is reached from a nonced
    // entry: the preloads must carry it too.
    preg_match_all('/<link\b[^>]*rel="modulepreload"[^>]*>/', $content, $preloads);

    foreach ($preloads[0] as $tag) {
        expect($tag)->toContain('nonce="'.$matches[1].'"');
    }
});

it('stands the policy down for a dev server no host-source can name', function () {
    $hotFile = storage_path('framework/testing/ipv6-hot');
    file_put_contents($hotFile, 'http://[::1]:5173');
    Vite::useHotFile($hotFile);

    try {
        // Sending a policy that silently drops the only origin serving the
        // assets is worse than sending none: the developer gets a blank page
        // and no useful signal.
        get(route('home'))->assertOk()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('X-Frame-Options', 'DENY');
    } finally {
        @unlink($hotFile);
    }
});

it('names a dev server it can express', function () {
    $hotFile = storage_path('framework/testing/named-hot');
    file_put_contents($hotFile, 'http://localhost:5173');
    Vite::useHotFile($hotFile);

    try {
        $response = get(route('home'))->assertOk();

        expect(directive($response, 'script-src'))->toContain('http://localhost:5173')
            ->and(directive($response, 'connect-src'))->toContain('ws://localhost:5173');
    } finally {
        @unlink($hotFile);
    }
});
