<?php

use App\Models\User;
use Illuminate\Support\Str;

/*
 * The connection an AI client makes, end to end.
 *
 * This is the half of the feature nobody can see: a person types one line into
 * their assistant and everything below happens in a browser tab. It is pinned
 * here because every part of it is invisible until it breaks.
 */

test('a guest sent to the authorize endpoint is asked to sign in first', function () {
    usePassportKeys();

    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'Guest Flow Client',
        'redirect_uris' => ['https://client.test/callback'],
    ])->assertCreated()->json('client_id');

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.test/callback',
        'scope' => 'mcp:use',
        'code_challenge' => pkceChallenge(Str::random(64)),
        'code_challenge_method' => 'S256',
    ]);

    $this->get("/oauth/authorize?{$query}")->assertRedirect(route('login'));

    // The authorize URL is kept so signing in returns the person to the consent screen.
    expect(session('url.intended'))->toContain('/oauth/authorize');
});

test('registering part way through the flow returns to the consent screen', function () {
    $intended = url('/oauth/authorize?response_type=code&client_id=1&redirect_uri='
        .urlencode('https://client.test/callback').'&scope=mcp:use');

    $this->withSession(['url.intended' => $intended])
        ->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect($intended);

    $this->assertAuthenticated();
});

test('the authorization server metadata document is served', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonStructure(['issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint'])
        ->assertJsonPath('scopes_supported', ['mcp:use'])
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

test('the protected resource metadata document is served', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure(['resource', 'authorization_servers']);
});

test('a client can register itself, which is what makes the setup one line', function () {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Test MCP Client',
        'redirect_uris' => ['https://client.test/callback'],
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['client_id', 'grant_types', 'redirect_uris'])
        ->assertJsonPath('token_endpoint_auth_method', 'none');

    $this->assertDatabaseHas('oauth_clients', [
        'id' => $response->json('client_id'),
        'name' => 'Test MCP Client',
    ]);
});

test('an unauthenticated MCP call is refused with a challenge the client can act on', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => new stdClass,
    ]);

    $response->assertUnauthorized();

    // Without this header the client has nothing to discover the OAuth server from.
    expect($response->headers->get('WWW-Authenticate'))->not->toBeNull();
});

test('the consent screen is always shown, even to a signed-in user', function () {
    usePassportKeys();

    $user = User::factory()->create();

    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'Consent Client',
        'redirect_uris' => ['https://client.test/callback'],
    ])->assertCreated()->json('client_id');

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.test/callback',
        'scope' => 'mcp:use',
        'code_challenge' => pkceChallenge(Str::random(64)),
        'code_challenge_method' => 'S256',
    ]);

    $response = $this->actingAs($user)->get("/oauth/authorize?{$query}");

    // Never a silent redirect: every MCP client is a third party, so approval
    // is always a deliberate click (App\Models\Passport\Client).
    $response->assertOk();
    expect(html($response))->toContain('Consent Client')
        ->toContain('read-only');
});

test('the whole PKCE flow issues a token that can list the tools', function () {
    usePassportKeys();

    $user = User::factory()->create();

    $clientId = $this->postJson('/oauth/register', [
        'client_name' => 'PKCE MCP Client',
        'redirect_uris' => ['https://client.test/callback'],
    ])->assertCreated()->json('client_id');

    $codeVerifier = Str::random(64);
    $state = Str::random(40);

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.test/callback',
        'scope' => 'mcp:use',
        'state' => $state,
        'code_challenge' => pkceChallenge($codeVerifier),
        'code_challenge_method' => 'S256',
    ]);

    $this->actingAs($user)->get("/oauth/authorize?{$query}")->assertOk();

    $approve = $this->actingAs($user)->post('/oauth/authorize', [
        'auth_token' => session('authToken'),
        'state' => $state,
        'client_id' => $clientId,
    ]);

    $approve->assertRedirect();
    parse_str((string) parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $callback);

    expect($callback)->toHaveKey('code');
    expect($callback['state'])->toBe($state);

    $token = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'https://client.test/callback',
        'code_verifier' => $codeVerifier,
        'code' => $callback['code'],
    ]);

    $token->assertOk();

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer '.$token->json('access_token'),
            'Accept' => 'application/json, text/event-stream',
        ])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => new stdClass,
        ]);

    $response->assertOk();
    expect($response->getContent())->toContain('search-logs')->toContain('get-trace');
});
