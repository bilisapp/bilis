<?php

namespace App\Services\Alerting\Webhooks;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A webhook URL that passed {@see SafeWebhookUrl::resolve()}, with the one
 * address it was checked against.
 *
 * `$url` is rebuilt from the parsed parts (no userinfo, no fragment), so curl
 * never parses a string PHP read differently. Always send through
 * {@see self::pendingRequest()} and to `$url`: the connection is pinned to `$ip`, so
 * a DNS answer that changes between the check and the connect is never asked.
 *
 * The constructor refuses an `$ip` that is not a public address, so a target
 * built anywhere but {@see SafeWebhookUrl::resolve()} still cannot point the
 * pin at a private or reserved address.
 */
final readonly class ResolvedWebhookTarget
{
    public const TIMEOUT_SECONDS = 5;

    /**
     * @throws UnsafeWebhookUrl when `$ip` is not a public address
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {
        if (! SafeWebhookUrl::isPublicAddress($ip)) {
            throw new UnsafeWebhookUrl('A webhook target must be pinned to a public address.');
        }
    }

    /**
     * The curl `--resolve` entry, or null when the URL names an IP literal
     * and there is nothing to resolve.
     */
    public function curlResolveEntry(): ?string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }

    /**
     * An HTTP request pinned to `$ip`, never following a redirect and never
     * through a proxy (a proxy resolves the host itself, bypassing the pin —
     * `HTTP(S)_PROXY` in the environment would otherwise apply), 5s to
     * connect and 5s in all. Send it to `$url`.
     */
    public function pendingRequest(): PendingRequest
    {
        $curl = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
        $entry = $this->curlResolveEntry();

        if ($entry !== null) {
            $curl[CURLOPT_RESOLVE] = [$entry];
        }

        return Http::withOptions(['curl' => $curl, 'proxy' => '', 'allow_redirects' => false])
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
