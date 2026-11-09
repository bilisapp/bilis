<?php

namespace App\Services\Alerting\Webhooks;

use InvalidArgumentException;

/**
 * Standard Webhooks signing (https://www.standardwebhooks.com/), symmetric `v1`.
 *
 * The secret is `whsec_` + base64 of 24–64 random bytes. A signature is
 * `v1,` + base64(HMAC-SHA256(secret bytes, "{id}.{timestamp}.{body}")), sent
 * in `webhook-signature` beside `webhook-id` and `webhook-timestamp`, so a
 * receiver can verify with any off-the-shelf Standard Webhooks library.
 *
 * The message id must stay the same across retries of one message: receivers
 * use it to de-duplicate.
 */
final class StandardWebhookSigner
{
    public const PREFIX = 'whsec_';

    public const TOLERANCE_SECONDS = 300;

    private readonly string $key;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $secret)
    {
        $encoded = str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret;
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) < 24 || strlen($key) > 64) {
            throw new InvalidArgumentException('A webhook secret is whsec_ followed by base64 of 24 to 64 bytes.');
        }

        $this->key = $key;
    }

    public static function generateSecret(int $bytes = 32): string
    {
        if ($bytes < 24 || $bytes > 64) {
            throw new InvalidArgumentException('A webhook secret is 24 to 64 bytes.');
        }

        return self::PREFIX.base64_encode(random_bytes($bytes));
    }

    public function sign(string $msgId, int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$msgId}.{$timestamp}.{$body}", $this->key, true));
    }

    /**
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public function headers(string $msgId, int $timestamp, string $body): array
    {
        return [
            'webhook-id' => $msgId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $this->sign($msgId, $timestamp, $body),
        ];
    }

    /**
     * Whether the headers carry a valid `v1` signature of the body, within
     * five minutes of `$now`. Header names are matched case-insensitively and
     * the signature header may list several, space-separated.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function verify(string $body, array $headers, ?int $now = null): bool
    {
        $headers = array_change_key_case($headers);
        $read = function (string $name) use ($headers): string {
            $value = $headers[$name] ?? '';

            return is_array($value) ? ($value[0] ?? '') : $value;
        };

        $msgId = $read('webhook-id');
        $timestamp = $read('webhook-timestamp');

        if ($msgId === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = $this->sign($msgId, (int) $timestamp, $body);

        foreach (explode(' ', $read('webhook-signature')) as $candidate) {
            if (str_starts_with($candidate, 'v1,') && hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
