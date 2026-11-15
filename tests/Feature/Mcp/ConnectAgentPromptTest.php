<?php

use App\Models\Passport\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Record an access token a client was issued for the user, as the OAuth flow
 * would.
 *
 * @param  array{revoked?: bool, expires_at?: DateTimeInterface}  $overrides
 */
function issueAgentToken(User $user, array $overrides = []): void
{
    DB::table('oauth_access_tokens')->insert([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => Client::factory()->asPublic()->create()->getKey(),
        'name' => null,
        'scopes' => '[]',
        'revoked' => $overrides['revoked'] ?? false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => $overrides['expires_at'] ?? now()->addDay(),
    ]);
}

beforeEach(function () {
    Http::fake();
});

test('every page is told where the MCP server is and that no agent is connected yet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('agent.mcpUrl', url('/mcp'))
            ->where('agent.connected', false));
});

test('an approved agent turns the prompt off', function () {
    $user = User::factory()->create();
    issueAgentToken($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('agent.connected', true));
});

test('an access token that lapsed inside the refresh window still counts as connected', function () {
    $user = User::factory()->create();
    issueAgentToken($user, ['expires_at' => now()->subDays(10)]);

    expect($user->hasConnectedAgent())->toBeTrue();
});

test('revoked tokens, tokens past the refresh window and other users tokens do not count', function () {
    $user = User::factory()->create();
    issueAgentToken($user, ['revoked' => true]);
    issueAgentToken($user, ['expires_at' => now()->subDays(31)]);
    issueAgentToken(User::factory()->create());

    expect($user->hasConnectedAgent())->toBeFalse();
});
