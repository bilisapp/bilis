<?php

namespace App\Services\Ingest;

use App\Models\ProjectApiKey;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Collection;

/**
 * Reads the ingest throttle's own counters back out for the dashboard.
 *
 * The limiter is defined in AppServiceProvider as a named limiter (`ingest`)
 * bucketed on the sha256 of the raw API key — which is exactly the `key_hash`
 * already stored on ProjectApiKey, so a key's current usage can be read
 * without ever seeing the plaintext again.
 *
 * @phpstan-type KeyUsage array{project: string, projectSlug: string, name: string, keyPrefix: string, attempts: int, remaining: int}
 * @phpstan-type UsageResult array{limit: int, disabled: bool, keys: list<KeyUsage>}
 */
class IngestRateUsage
{
    /**
     * The named limiter the ingest routes are throttled by (`throttle:ingest`).
     */
    public const LIMITER = 'ingest';

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * The limiter bucket for a key, given the sha256 already stored on it.
     *
     * The one definition of the bucket string: AppServiceProvider hands the
     * limiter this, and this class reads the counter back. If the two ever
     * disagree the panel silently reports zero forever, so they share it.
     */
    public static function bucketForKeyHash(string $keyHash): string
    {
        return 'ingest:key:'.$keyHash;
    }

    /**
     * The limiter bucket for an unauthenticated request, by address.
     */
    public static function bucketForIp(?string $ip): string
    {
        return 'ingest:ip:'.$ip;
    }

    /**
     * The cache key `ThrottleRequests` counts a named limiter's bucket under.
     *
     * Not derivable from `Limit` alone: the middleware prefixes the limiter
     * name and hashes the pair — `md5($limiterName.$limit->key)` — before it
     * ever reaches `RateLimiter`. Reproduced here rather than guessed, because
     * a mismatch reads an empty counter instead of failing loudly.
     */
    public static function counterKey(string $bucket): string
    {
        return md5(self::LIMITER.$bucket);
    }

    /**
     * Per-key ingest usage for the current minute.
     *
     * The window is the limiter's own rolling minute, so the numbers are
     * ephemeral by construction — they are a live throughput reading, never a
     * history. A limit of 0 disables the limiter entirely; the keys are still
     * listed, with their counters at zero, because nothing is being counted.
     *
     * @param  Collection<int, ProjectApiKey>  $apiKeys
     * @return UsageResult
     */
    public function forKeys(Collection $apiKeys): array
    {
        $limit = (int) config('security.ingest_rate_limit');
        $disabled = $limit <= 0;

        $keys = [];

        foreach ($apiKeys as $apiKey) {
            $attempts = $disabled
                ? 0
                : (int) $this->limiter->attempts(self::counterKey(
                    self::bucketForKeyHash($apiKey->key_hash),
                ));

            $keys[] = [
                'project' => $apiKey->project->name,
                'projectSlug' => $apiKey->project->slug,
                'name' => $apiKey->name,
                'keyPrefix' => $apiKey->key_prefix,
                'attempts' => $attempts,
                'remaining' => $disabled ? 0 : max(0, $limit - $attempts),
            ];
        }

        return [
            'limit' => $disabled ? 0 : $limit,
            'disabled' => $disabled,
            'keys' => $keys,
        ];
    }
}
