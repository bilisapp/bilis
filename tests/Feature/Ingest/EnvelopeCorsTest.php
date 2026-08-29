<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->publicKey = 'bilis_pk_'.str_repeat('e', 40);
    $this->project = Project::factory()->create(['allowed_origins' => ['https://shop.example.com']]);
    ProjectApiKey::factory()->forProject($this->project)->withPublicKey($this->publicKey)->create();
});

/**
 * The preflight a browser sends: no credentials, no body, key in the query.
 */
function preflight(string $publicKey, string $origin, array $headers = []): TestResponse
{
    return test()->call('OPTIONS', '/api/1/envelope/?sentry_key='.$publicKey, [], [], [], array_merge([
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ], $headers));
}

/**
 * The POST the browser makes once the preflight has allowed it.
 */
function postFromBrowser(string $publicKey, string $origin): TestResponse
{
    $body = "{\"event_id\":\"a\"}\n{\"type\":\"event\"}\n{\"message\":\"From the page\"}\n";

    return test()->call('POST', '/api/1/envelope/?sentry_key='.$publicKey, [], [], [], [
        'HTTP_ORIGIN' => $origin,
    ], $body);
}

test('a preflight from an allowed origin is answered with permission', function () {
    preflight($this->publicKey, 'https://shop.example.com')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->assertHeader('Access-Control-Max-Age', '3600')
        ->assertHeader('Vary', 'Origin');
});

test('a preflight echoes back the headers the client asked to send', function () {
    preflight($this->publicKey, 'https://shop.example.com', [
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, x-sentry-auth',
    ])->assertHeader('Access-Control-Allow-Headers', 'content-type, x-sentry-auth');
});

test('an origin that is not on the list is answered without permission', function () {
    $response = preflight($this->publicKey, 'https://evil.example.com');

    // The request is answered, not rejected: it is the missing header that
    // stops the browser, and a 403 here would only be a slower way to say so.
    $response->assertNoContent()
        ->assertHeader('Vary', 'Origin')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('a project with no origins configured answers no browser at all', function () {
    $this->project->update(['allowed_origins' => []]);

    preflight($this->publicKey, 'https://shop.example.com')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    postFromBrowser($this->publicKey, 'https://shop.example.com')
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('an unknown public key allows no origin', function () {
    preflight('bilis_pk_'.str_repeat('z', 40), 'https://shop.example.com')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('the post that follows carries the header the browser needs to read it', function () {
    postFromBrowser($this->publicKey, 'https://shop.example.com')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com')
        ->assertHeader('Vary', 'Origin');

    Http::assertSent(fn ($request) => insertedRows($request)[0]['Body'] === 'From the page');
});

test('a wildcard entry covers one subdomain label', function () {
    $this->project->update(['allowed_origins' => ['https://*.example.com']]);

    preflight($this->publicKey, 'https://app.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');

    preflight($this->publicKey, 'https://deep.app.example.com')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    // The classic near miss: a suffix match that is not a subdomain at all.
    preflight($this->publicKey, 'https://example.com.attacker.test')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('a lone asterisk echoes whatever origin asked', function () {
    $this->project->update(['allowed_origins' => ['*']]);

    // Echoed, never sent literally: `*` and credentials are incompatible, and
    // an echo keeps the door open for the SDKs that do send them.
    preflight($this->publicKey, 'https://anything.example.com')
        ->assertHeader('Access-Control-Allow-Origin', 'https://anything.example.com');
});

test('a request without an origin is left alone', function () {
    $response = test()->call('OPTIONS', '/api/1/envelope/?sentry_key='.$this->publicKey);

    $response->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('the preflight is not throttled', function () {
    config(['security.ingest_rate_limit_unauthenticated' => 1]);

    preflight($this->publicKey, 'https://shop.example.com')->assertNoContent();
    preflight($this->publicKey, 'https://shop.example.com')->assertNoContent();
    preflight($this->publicKey, 'https://shop.example.com')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com');
});

test('the versioned endpoints keep the wildcard they were given', function () {
    // Narrowing `cors.paths` to `api/v1/*` is what lets the per-project list
    // above be the only voice on the DSN routes; it must not have quietly
    // taken CORS away from the endpoints that are meant to have it.
    $this->call('OPTIONS', '/api/v1/ingest', [], [], [], [
        'HTTP_ORIGIN' => 'https://anything.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->assertHeader('Access-Control-Allow-Origin', '*');
});
