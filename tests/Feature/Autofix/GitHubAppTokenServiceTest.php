<?php

use App\Models\GitHubInstallation;
use App\Services\Autofix\GitHubAppException;
use App\Services\Autofix\GitHubAppTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Generate a throwaway RSA keypair and point the config at it.
 *
 * @return array{0: string, 1: string} the private PEM and the public PEM
 */
function autofixAppKeypair(bool $base64 = true): array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    openssl_pkey_export($key, $privatePem);

    $details = openssl_pkey_get_details($key);

    config()->set('autofix.github.app_id', '123456');
    config()->set('autofix.github.private_key', $base64 ? base64_encode($privatePem) : $privatePem);

    return [$privatePem, $details['key']];
}

/**
 * Decode one base64url JWT segment.
 *
 * @return array<string, mixed>
 */
function decodeJwtSegment(string $segment): array
{
    return json_decode(base64_decode(strtr($segment, '-_', '+/')), true);
}

test('the app jwt is an RS256 token signed with the app private key', function () {
    [, $publicPem] = autofixAppKeypair();

    $jwt = app(GitHubAppTokenService::class)->appJwt();

    [$header, $payload, $signature] = explode('.', $jwt);

    expect(decodeJwtSegment($header))->toBe(['alg' => 'RS256', 'typ' => 'JWT']);

    $claims = decodeJwtSegment($payload);

    expect($claims['iss'])->toBe('123456')
        ->and($claims['exp'] - $claims['iat'])->toBe(GitHubAppTokenService::JWT_TTL_SECONDS)
        ->and($claims['iat'])->toBeLessThanOrEqual(time())
        ->and($claims['exp'])->toBeGreaterThan(time());

    $verified = openssl_verify(
        $header.'.'.$payload,
        base64_decode(strtr($signature, '-_', '+/')),
        $publicPem,
        OPENSSL_ALGO_SHA256,
    );

    expect($verified)->toBe(1);
});

test('a raw pem private key is accepted as well as a base64 encoded one', function () {
    [, $publicPem] = autofixAppKeypair(base64: false);

    $jwt = app(GitHubAppTokenService::class)->appJwt();

    [$header, $payload, $signature] = explode('.', $jwt);

    expect(openssl_verify(
        $header.'.'.$payload,
        base64_decode(strtr($signature, '-_', '+/')),
        $publicPem,
        OPENSSL_ALGO_SHA256,
    ))->toBe(1);
});

test('an unusable private key raises a domain exception', function () {
    config()->set('autofix.github.app_id', '123456');
    config()->set('autofix.github.private_key', 'not-a-key');

    app(GitHubAppTokenService::class)->appJwt();
})->throws(GitHubAppException::class);

test('a missing app id raises a domain exception', function () {
    config()->set('autofix.github.app_id', null);

    app(GitHubAppTokenService::class)->appJwt();
})->throws(GitHubAppException::class);

test('the installation token is exchanged for the requested repository and scopes', function () {
    autofixAppKeypair();

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_readtoken',
            'expires_at' => now()->addHour()->toIso8601String(),
        ], 201),
    ]);

    $installation = GitHubInstallation::factory()->create(['installation_id' => 42]);

    $token = app(GitHubAppTokenService::class)
        ->installationToken($installation, 'acme/app', ['contents' => 'read']);

    expect($token)->toBe('ghs_readtoken');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.github.com/app/installations/42/access_tokens'
            && $request->method() === 'POST'
            && $request['repositories'] === ['app']
            && $request['permissions'] === ['contents' => 'read']
            && str_starts_with($request->header('Authorization')[0], 'Bearer ey');
    });
});

test('read scoped tokens are cached per installation, repository and permission set', function () {
    autofixAppKeypair();

    $tokens = ['ghs_first', 'ghs_second', 'ghs_third'];

    Http::fake([
        'api.github.com/*' => Http::sequence()
            ->push(['token' => $tokens[0]], 201)
            ->push(['token' => $tokens[1]], 201)
            ->push(['token' => $tokens[2]], 201),
    ]);

    $service = app(GitHubAppTokenService::class);
    $installation = GitHubInstallation::factory()->create(['installation_id' => 42]);
    $other = GitHubInstallation::factory()->create(['installation_id' => 43]);

    expect($service->installationToken($installation, 'acme/app', ['contents' => 'read']))->toBe('ghs_first')
        ->and($service->installationToken($installation, 'acme/app', ['contents' => 'read']))->toBe('ghs_first');

    Http::assertSentCount(1);

    expect($service->installationToken($installation, 'acme/other', ['contents' => 'read']))->toBe('ghs_second')
        ->and($service->installationToken($other, 'acme/app', ['contents' => 'read']))->toBe('ghs_third');

    Http::assertSentCount(3);
});

test('write scoped tokens are never cached', function () {
    autofixAppKeypair();

    Http::fake([
        'api.github.com/*' => Http::sequence()
            ->push(['token' => 'ghs_write_one'], 201)
            ->push(['token' => 'ghs_write_two'], 201),
    ]);

    $service = app(GitHubAppTokenService::class);
    $installation = GitHubInstallation::factory()->create();
    $permissions = ['contents' => 'write', 'pull_requests' => 'write'];

    expect($service->installationToken($installation, 'acme/app', $permissions))->toBe('ghs_write_one')
        ->and($service->installationToken($installation, 'acme/app', $permissions))->toBe('ghs_write_two');

    Http::assertSentCount(2);
});

test('a refused token exchange raises a domain exception carrying the status', function () {
    autofixAppKeypair();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $installation = GitHubInstallation::factory()->create();

    expect(fn () => app(GitHubAppTokenService::class)
        ->installationToken($installation, 'acme/app', ['contents' => 'read']))
        ->toThrow(function (GitHubAppException $exception) {
            expect($exception->statusCode())->toBe(401)
                ->and($exception->isTransient())->toBeFalse();
        });
});

test('a rate limited token exchange is reported as transient', function () {
    autofixAppKeypair();

    Http::fake(['api.github.com/*' => Http::response(['message' => 'slow down'], 429)]);

    $installation = GitHubInstallation::factory()->create();

    expect(fn () => app(GitHubAppTokenService::class)
        ->installationToken($installation, 'acme/app', ['contents' => 'read']))
        ->toThrow(fn (GitHubAppException $exception) => expect($exception->isTransient())->toBeTrue());
});

test('a token response without a token raises a domain exception', function () {
    autofixAppKeypair();

    Http::fake(['api.github.com/*' => Http::response(['expires_at' => 'soon'], 201)]);

    $installation = GitHubInstallation::factory()->create();

    app(GitHubAppTokenService::class)->installationToken($installation, 'acme/app', ['contents' => 'read']);
})->throws(GitHubAppException::class);
