<?php

namespace App\Services\Autofix;

use App\Models\GitHubInstallation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Mints installation access tokens for the autofix GitHub App.
 *
 * Two call sites, two scopes: a read token (`contents: read`) travels to Ayos
 * so it can clone, and a write token (`contents`/`pull_requests: write`) never
 * leaves the pull request publisher. `workflows` is never requested.
 *
 * The App private key stays in config and is only ever used here, to sign the
 * short-lived App JWT that buys an installation token.
 */
class GitHubAppTokenService
{
    /**
     * The GitHub REST API root.
     */
    public const API_URL = 'https://api.github.com';

    /**
     * The API version every request pins itself to.
     */
    public const API_VERSION = '2022-11-28';

    /**
     * How long the App JWT is valid for. GitHub rejects anything over 10 min.
     */
    public const JWT_TTL_SECONDS = 600;

    /**
     * The clock drift allowance subtracted from the JWT's issued-at claim.
     */
    public const JWT_CLOCK_SKEW_SECONDS = 60;

    /**
     * How long a read-only installation token is cached for. GitHub expires
     * them after an hour, so 50 minutes always hands back a usable token.
     */
    public const TOKEN_CACHE_SECONDS = 3000;

    /**
     * The cache key prefix for installation tokens.
     */
    public const CACHE_PREFIX = 'autofix:github:installation-token:';

    /**
     * Get an installation token scoped to one repository and permission set.
     *
     * Read-only scopes are cached; anything that can write is fetched fresh so
     * a write token never outlives the operation that asked for it.
     *
     * `$fresh` bypasses the cache for a read token too, and exists for exactly
     * one caller. Ayos now runs in the same container as the agent, so it
     * revokes its clone token the moment the clone finishes — which makes that
     * token single-use. Serving the next job a cached copy would dispatch it
     * with a credential the previous run had already destroyed, and the failure
     * would look like a permissions problem rather than a caching one.
     *
     * @param  array<string, string>  $permissions
     *
     * @throws GitHubAppException
     */
    public function installationToken(GitHubInstallation $installation, string $repo, array $permissions, bool $fresh = false): string
    {
        if ($fresh || ! $this->isReadOnly($permissions)) {
            return $this->requestToken($installation, $repo, $permissions);
        }

        $key = $this->cacheKey($installation, $repo, $permissions);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->requestToken($installation, $repo, $permissions);

        Cache::put($key, $token, self::TOKEN_CACHE_SECONDS);

        return $token;
    }

    /**
     * Build the App level JWT that authenticates the token exchange.
     *
     * RS256, signed with openssl directly so the app takes no new dependency
     * for a token this small.
     *
     * @throws GitHubAppException
     */
    public function appJwt(): string
    {
        $appId = config('autofix.github.app_id');

        if (! is_string($appId) && ! is_int($appId)) {
            throw GitHubAppException::missingConfiguration('autofix.github.app_id');
        }

        $appId = (string) $appId;

        if (trim($appId) === '') {
            throw GitHubAppException::missingConfiguration('autofix.github.app_id');
        }

        $issuedAt = time() - self::JWT_CLOCK_SKEW_SECONDS;

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::JWT_TTL_SECONDS,
            'iss' => $appId,
        ];

        $signingInput = $this->base64UrlEncode($this->encodeJson($header))
            .'.'
            .$this->base64UrlEncode($this->encodeJson($payload));

        return $signingInput.'.'.$this->base64UrlEncode($this->sign($signingInput));
    }

    /**
     * Exchange the App JWT for an installation token.
     *
     * @param  array<string, string>  $permissions
     *
     * @throws GitHubAppException
     */
    protected function requestToken(GitHubInstallation $installation, string $repo, array $permissions): string
    {
        $url = sprintf('%s/app/installations/%d/access_tokens', self::API_URL, $installation->installation_id);

        try {
            $response = Http::withToken($this->appJwt())
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => self::API_VERSION,
                ])
                ->timeout(10)
                ->post($url, [
                    'repositories' => [$this->repositoryName($repo)],
                    'permissions' => $permissions,
                ]);
        } catch (ConnectionException $exception) {
            throw GitHubAppException::fromConnectionException($exception, $installation->installation_id);
        }

        if ($response->failed()) {
            throw GitHubAppException::fromResponse($response, $installation->installation_id);
        }

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw GitHubAppException::fromInvalidResponse($installation->installation_id);
        }

        return $token;
    }

    /**
     * Sign the JWT input with the App private key.
     *
     * @throws GitHubAppException
     */
    protected function sign(string $signingInput): string
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());

        if ($privateKey === false) {
            throw GitHubAppException::invalidPrivateKey('the PEM could not be read: '.(openssl_error_string() ?: 'unknown error'));
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw GitHubAppException::invalidPrivateKey('signing failed: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $signature;
    }

    /**
     * Read the App private key out of config.
     *
     * The env value is base64 encoded PEM, because a PEM does not survive a
     * dotenv file intact. A raw PEM is accepted too, so a self-hosted operator
     * pasting the file contents into a secret manager is not punished for it.
     *
     * @throws GitHubAppException
     */
    protected function privateKeyPem(): string
    {
        $key = config('autofix.github.private_key');

        if (! is_string($key) || trim($key) === '') {
            throw GitHubAppException::missingConfiguration('autofix.github.private_key');
        }

        $key = trim($key);

        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        $decoded = base64_decode($key, true);

        if ($decoded === false || ! str_contains($decoded, '-----BEGIN')) {
            throw GitHubAppException::invalidPrivateKey('it is neither a PEM nor base64 encoded PEM');
        }

        return $decoded;
    }

    /**
     * Determine whether every requested permission is read-only.
     *
     * @param  array<string, string>  $permissions
     */
    protected function isReadOnly(array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $access) {
            if (strtolower($access) !== 'read') {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the cache key for one installation, repository and permission set.
     *
     * @param  array<string, string>  $permissions
     */
    protected function cacheKey(GitHubInstallation $installation, string $repo, array $permissions): string
    {
        $normalized = array_change_key_case($permissions);
        ksort($normalized);

        return self::CACHE_PREFIX.hash('sha256', implode('|', [
            (string) $installation->installation_id,
            strtolower($repo),
            $this->encodeJson($normalized),
        ]));
    }

    /**
     * Reduce an "org/app" full name to the repository name GitHub expects in
     * the `repositories` scope list.
     */
    protected function repositoryName(string $repo): string
    {
        $segments = explode('/', trim($repo, '/'));

        return (string) end($segments);
    }

    /**
     * JSON encode a JWT segment.
     *
     * @param  array<string, mixed>  $value
     */
    protected function encodeJson(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Base64url encode without padding, as JWTs require.
     */
    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
