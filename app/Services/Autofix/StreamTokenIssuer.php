<?php

namespace App\Services\Autofix;

use App\Models\FixJob;
use App\Models\User;

/**
 * Mints the short-lived Ed25519 token a browser watches a job's stream with.
 *
 * This is the one credential that leaves Bilis for a user agent, so it is cut
 * as thin as it goes: one job, read only, ten minutes. Ayos holds nothing but
 * the public half — it can verify a token and never mint one — and enforces
 * `exp` at connect time, which is why the viewer reconnects with a fresh token
 * rather than holding one open.
 *
 * EdDSA is signed with libsodium, which ships with PHP: no JWT package, the
 * same instinct that keeps a hand-rolled RS256 in `GitHubAppTokenService`.
 */
class StreamTokenIssuer
{
    /**
     * The JWT `scope` claim every stream token carries.
     */
    public const SCOPE = 'stream:read';

    /**
     * The JOSE algorithm name for Ed25519 signatures.
     */
    public const ALGORITHM = 'EdDSA';

    /**
     * Mint a token authorising one viewer to watch one job.
     *
     * @return array{token: string, stream_url: string, expires_at: string}
     *
     * @throws StreamTokenException
     */
    public function issue(FixJob $job, User $viewer): array
    {
        $issuedAt = now()->getTimestamp();
        $expiresAt = $issuedAt + ($this->ttlMinutes() * 60);

        $header = ['alg' => self::ALGORITHM, 'typ' => 'JWT'];
        $payload = [
            'sub' => (string) $viewer->getKey(),
            'job' => $job->uuid,
            'scope' => self::SCOPE,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $signingInput = $this->encodeSegment($header).'.'.$this->encodeSegment($payload);
        $signature = sodium_crypto_sign_detached($signingInput, $this->secretKey());

        return [
            'token' => $signingInput.'.'.$this->base64UrlEncode($signature),
            'stream_url' => $this->streamUrl($job),
            'expires_at' => now()->setTimestamp($expiresAt)->toISOString(),
        ];
    }

    /**
     * The browser-facing stream endpoint for one job.
     *
     * The token is not baked in here: the URL is stable for the life of the
     * page and the viewer appends a freshly minted token on every connect.
     *
     * @throws StreamTokenException
     */
    public function streamUrl(FixJob $job): string
    {
        $base = config('autofix.ayos.stream_url');

        if (! is_string($base) || trim($base) === '') {
            throw StreamTokenException::missingStreamUrl();
        }

        return rtrim(trim($base), '/').'/jobs/'.$job->uuid.'/stream';
    }

    /**
     * Whether a stream can be offered at all on this instance.
     */
    public function isConfigured(): bool
    {
        $key = config('autofix.stream_jwt.private_key');
        $url = config('autofix.ayos.stream_url');

        return is_string($key) && trim($key) !== '' && is_string($url) && trim($url) !== '';
    }

    /**
     * How long a minted token stays acceptable at connect time.
     */
    public function ttlMinutes(): int
    {
        return max(1, (int) config('autofix.stream_jwt.ttl_minutes', 10));
    }

    /**
     * Read the Ed25519 secret key out of configuration.
     *
     * Both shapes libsodium hands an operator are accepted: the 64 byte secret
     * key `crypto_sign_keypair` produces, and the 32 byte seed it was derived
     * from — the second is what most key generators print, and refusing it
     * would only teach people to paste the wrong thing.
     *
     * @return non-empty-string
     *
     * @throws StreamTokenException
     */
    protected function secretKey(): string
    {
        $configured = config('autofix.stream_jwt.private_key');

        if (! is_string($configured) || trim($configured) === '') {
            throw StreamTokenException::missingKey();
        }

        $decoded = base64_decode(trim($configured), true);

        if ($decoded === false) {
            throw StreamTokenException::invalidKey('it is not valid base64');
        }

        return match (strlen($decoded)) {
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => $decoded,
            SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_secretkey(
                sodium_crypto_sign_seed_keypair($decoded),
            ),
            default => throw StreamTokenException::invalidKey(sprintf(
                'it decodes to %d bytes, expected %d (secret key) or %d (seed)',
                strlen($decoded),
                SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
                SODIUM_CRYPTO_SIGN_SEEDBYTES,
            )),
        };
    }

    /**
     * JSON encode and base64url a JWT segment.
     *
     * @param  array<string, mixed>  $segment
     */
    protected function encodeSegment(array $segment): string
    {
        return $this->base64UrlEncode((string) json_encode($segment, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Base64url encode without padding, as JWTs require.
     */
    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
