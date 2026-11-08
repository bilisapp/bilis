<?php

namespace App\Services\Alerting\Webhooks;

/**
 * The SSRF guard for customer-supplied webhook URLs (Slack and generic).
 *
 * A customer names the URL and the box also runs ClickHouse on localhost, so:
 *
 * - {@see validate()} — syntax, for the form: absolute https URL (http only
 *   when `$allowHttp`), no userinfo, default port only, no whitespace or
 *   backslash, an ASCII host, an optional exact host (`hooks.slack.com`), and
 *   IP literals only in canonical form and only when public.
 * - {@see resolve()} — at send time: every A and AAAA record must be public,
 *   or the whole URL is refused. The result is pinned to one checked address
 *   ({@see ResolvedWebhookTarget::pendingRequest()}).
 *
 * Every refusal is an {@see UnsafeWebhookUrl} whose message is for the user.
 */
final class SafeWebhookUrl
{
    /**
     * Ranges `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` lets
     * through (checked on PHP 8.4), refused here explicitly:
     *
     * - IPv4: CGNAT 100.64/10, IETF protocol assignments 192.0.0/24, the three
     *   documentation nets, the 6to4 relay 192.88.99/24, benchmarking
     *   198.18/15 and multicast 224/4.
     * - IPv6: the discard prefix 100::/64, local-use NAT64 64:ff9b:1::/48,
     *   Teredo 2001::/32, documentation 2001:db8::/32, 6to4 2002::/16,
     *   site-local fec0::/10 and multicast ff00::/8 (Teredo and 6to4 tunnel
     *   to an IPv4 address; both are obsolete, so they are refused whole).
     *
     * IPv6 forms that carry an IPv4 address in their last 32 bits (mapped
     * ::ffff:0:0/96, compatible ::/96, SIIT ::ffff:0:0:0/96, NAT64
     * 64:ff9b::/96) are judged by that IPv4 address, see {@see embeddedIpv4()}.
     * PHP 8.4 already refuses all of ::ffff:0:0/96; the others it misses.
     *
     * @var list<string>
     */
    private const EXTRA_BLOCKED = [
        '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4',
        '100::/64', '64:ff9b:1::/48', '2001::/32', '2001:db8::/32', '2002::/16', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(
        private readonly WebhookHostResolver $resolver,
        private readonly bool $allowHttp = false,
    ) {}

    /**
     * https-only everywhere but the `local` and `testing` environments.
     */
    public static function forEnvironment(): self
    {
        return new self(app(WebhookHostResolver::class), app()->environment('local', 'testing'));
    }

    /**
     * @throws UnsafeWebhookUrl
     */
    public function validate(string $url, ?string $requiredHost = null): void
    {
        $this->parse($url, $requiredHost);
    }

    /**
     * Resolve the host now and refuse unless every address is public.
     *
     * @throws UnsafeWebhookUrl
     */
    public function resolve(string $url, ?string $requiredHost = null): ResolvedWebhookTarget
    {
        $target = $this->parse($url, $requiredHost);

        if (filter_var($target->host, FILTER_VALIDATE_IP) !== false) {
            return $target;
        }

        $addresses = $this->resolver->resolve($target->host);

        if ($addresses === []) {
            throw new UnsafeWebhookUrl("The host {$target->host} does not resolve to an address.");
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new UnsafeWebhookUrl("The host {$target->host} resolves to a private or reserved address, which webhooks cannot be sent to.");
            }
        }

        return new ResolvedWebhookTarget($target->url, $target->host, $target->port, $addresses[0]);
    }

    /**
     * Whether a connection to this address leaves the box for the public
     * internet. Anything that is not a valid IP is not public.
     */
    public static function isPublicAddress(string $ip): bool
    {
        $embedded = self::embeddedIpv4($ip);

        if ($embedded !== null && ! self::isPublicAddress($embedded)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The URL parsed into a target whose address is still unknown, unless it
     * is an IP literal, which is then also checked.
     *
     * @throws UnsafeWebhookUrl
     */
    private function parse(string $url, ?string $requiredHost): ResolvedWebhookTarget
    {
        if (strlen($url) > 2048 || preg_match('/[\s\\\\\x00-\x1f\x7f]/', $url) === 1) {
            throw new UnsafeWebhookUrl('The URL contains spaces, backslashes or control characters.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new UnsafeWebhookUrl('Enter a full URL, starting with https://.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowHttp)) {
            throw new UnsafeWebhookUrl('The URL must start with https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('The URL must not contain a user name or password.');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;

        if (isset($parts['port']) && $parts['port'] !== $defaultPort) {
            throw new UnsafeWebhookUrl("The URL must use the default port ({$defaultPort}).");
        }

        $host = rtrim(strtolower($parts['host']), '.');
        $ip = $this->literalIp($host);

        if ($ip === null) {
            $this->assertHostname($host);
        } elseif (! self::isPublicAddress($ip)) {
            throw new UnsafeWebhookUrl('The URL points at a private or reserved address, which webhooks cannot be sent to.');
        }

        if ($requiredHost !== null && $host !== strtolower($requiredHost)) {
            throw new UnsafeWebhookUrl("The URL must be on {$requiredHost}.");
        }

        $authority = $ip !== null && str_contains($ip, ':') ? "[{$ip}]" : ($ip ?? $host);
        $rebuilt = "{$scheme}://{$authority}".($parts['path'] ?? '/')
            .(isset($parts['query']) ? "?{$parts['query']}" : '');

        return new ResolvedWebhookTarget($rebuilt, $ip ?? $host, $defaultPort, $ip ?? '');
    }

    /**
     * The address an IP-literal host names, or null for a hostname. IPv4 is
     * accepted only as four plain decimals: curl and `inet_aton` also read
     * `2130706433`, `0x7f.1`, `0177.0.0.1` and `127.1` as 127.0.0.1, so any
     * host whose last label is numeric must be canonical or it is refused.
     *
     * @throws UnsafeWebhookUrl
     */
    private function literalIp(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);

            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new UnsafeWebhookUrl('The URL contains an IPv6 address that cannot be read.');
            }

            return $inner;
        }

        $labels = explode('.', $host);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', end($labels)) !== 1) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new UnsafeWebhookUrl('Write an IP address as four decimal numbers, like 203.0.113.7.');
        }

        return $host;
    }

    /**
     * @throws UnsafeWebhookUrl
     */
    private function assertHostname(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new UnsafeWebhookUrl('The URL host is not a valid host name; write international names in their xn-- form.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new UnsafeWebhookUrl('Webhooks cannot be sent to localhost.');
        }
    }

    /**
     * The IPv4 address in the last 32 bits of an IPv4-mapped, -compatible,
     * SIIT or well-known-prefix NAT64 IPv6 address.
     */
    private static function embeddedIpv4(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $bytes = (string) inet_pton($ip);

        $lastFour = match (true) {
            str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff"),
            str_starts_with($bytes, str_repeat("\0", 12)),
            str_starts_with($bytes, str_repeat("\0", 8)."\xff\xff\0\0"),
            str_starts_with($bytes, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($bytes, 12, 4),
            default => null,
        };

        return $lastFour === null ? null : (string) inet_ntop($lastFour);
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $address = (string) @inet_pton($ip);
        $prefix = (string) inet_pton($network);

        if (strlen($address) !== strlen($prefix)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);

        if (substr($address, 0, $whole) !== substr($prefix, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($prefix[$whole]) & $mask);
    }
}
