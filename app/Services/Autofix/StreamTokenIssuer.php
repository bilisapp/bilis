<?php

namespace App\Services\Autofix;

use App\Models\FixJob;
use App\Models\User;

/**
 * Mints the short-lived Ed25519 token a browser watches a job's stream with.
 *
 * The stream is served by Bilis now — a container run has nothing listening, so
 * it POSTs its events here and this application fans them out. That makes the
 * token same-origin and, strictly speaking, redundant with the session.
 *
 * It is kept for the one thing a session cannot say: WHICH job the viewer asked
 * to watch. `FixJobStreamController` authorises with the policy and then checks
 * this token names the same job, so a client holding a valid token for another
 * job fails rather than being handed a transcript it is entitled to but did not
 * ask for. The policy is the authority; this is a scoping check.
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
     * A Bilis route now, not an Ayos one. The token is not baked in: the URL is
     * stable for the life of the page and the viewer appends a freshly minted
     * token on every connect.
     */
    public function streamUrl(FixJob $job): string
    {
        return route('autofix.stream', [
            'current_team' => $job->project->team->slug,
            'fixJob' => $job->uuid,
        ]);
    }

    /**
     * Determine whether a token is a live one for this job.
     *
     * Deliberately total: any failure — malformed, wrong scope, wrong job,
     * expired, unverifiable — is a false rather than an exception, because the
     * caller's only question is whether to serve the stream.
     *
     * `exp` IS enforced here, unlike in the old cross-origin design where an
     * established connection could not be reached to close. A connection is now
     * bounded anyway, and the viewer mints a fresh token on every reconnect.
     */
    public function accepts(string $token, FixJob $job): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        [$header, $payload, $signature] = $parts;

        $raw = $this->base64UrlDecode($signature);
        $claims = json_decode((string) $this->base64UrlDecode($payload), true);

        if ($raw === false || ! is_array($claims)) {
            return false;
        }

        try {
            $publicKey = sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
        } catch (StreamTokenException) {
            return false;
        }

        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (! sodium_crypto_sign_verify_detached($raw, $header.'.'.$payload, $publicKey)) {
            return false;
        }

        return ($claims['scope'] ?? null) === self::SCOPE
            && ($claims['job'] ?? null) === $job->uuid
            && is_int($claims['exp'] ?? null)
            && $claims['exp'] > now()->getTimestamp();
    }

    /**
     * Whether a stream can be offered at all on this instance.
     */
    public function isConfigured(): bool
    {
        $key = config('autofix.stream_jwt.private_key');

        return is_string($key) && trim($key) !== '';
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
     * Base64url decode a JWT segment.
     */
    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * Base64url encode without padding, as JWTs require.
     */
    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
