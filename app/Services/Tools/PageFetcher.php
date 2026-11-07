<?php

namespace App\Services\Tools;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Fetch one public web page the way a crawler would: one GET, no JavaScript.
 *
 * A visitor names the URL, so this is the one place the site makes a request
 * to an address it did not choose, and it is guarded accordingly. Only http
 * and https on the default ports; every address the host resolves to must be
 * public (no loopback, private, link-local, CGNAT or reserved range), and the
 * connection is pinned to those addresses so DNS cannot change its answer
 * between the check and the connect. Redirects are followed by hand, each hop
 * checked the same way. The body is read to a cap and the clock is short.
 * Every refusal is a {@see PageFetchFailed} with a sentence for the visitor.
 */
class PageFetcher
{
    public const MAX_BYTES = 2_000_000;

    public const MAX_REDIRECTS = 5;

    /**
     * The user agents a visitor can send as. A server that varies on the
     * user agent (dynamic rendering, bot blocking) answers each differently.
     *
     * @var array<string, array{label: string, header: string}>
     */
    public const AGENTS = [
        'googlebot' => [
            'label' => 'Googlebot (smartphone)',
            'header' => 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.7390.122 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ],
        'bingbot' => [
            'label' => 'Bingbot',
            'header' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        ],
        'gptbot' => [
            'label' => 'GPTBot',
            'header' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
        ],
        'claudebot' => [
            'label' => 'ClaudeBot',
            'header' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        ],
        'browser' => [
            'label' => 'A desktop browser',
            'header' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        ],
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * What someone typed, as an absolute http(s) URL; a bare host gets https.
     *
     * @throws PageFetchFailed
     */
    public static function normalise(string $input): string
    {
        $url = trim($input);

        if ($url === '') {
            throw new PageFetchFailed('Enter the address of a page to fetch.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new PageFetchFailed('That does not look like a web address.');
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new PageFetchFailed('Only http and https addresses can be fetched.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PageFetchFailed('Addresses with a user name or password in them are not fetched.');
        }

        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            throw new PageFetchFailed('Only the standard ports, 80 and 443, are fetched.');
        }

        return (string) (new Uri($url))->withFragment('');
    }

    /**
     * @throws PageFetchFailed
     */
    public function fetch(string $input, string $agent): FetchedPage
    {
        $url = self::normalise($input);
        $userAgent = (self::AGENTS[$agent] ?? self::AGENTS['googlebot'])['header'];
        $redirects = [];
        $started = hrtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $uri = new Uri($url);
            try {
                $addresses = $this->publicAddresses($uri->getHost());
            } catch (PageFetchFailed $refused) {
                throw $hop === 0 ? $refused : self::refusedRedirect($refused);
            }

            $port = $uri->getPort() ?? ($uri->getScheme() === 'http' ? 80 : 443);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'curl' => [
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_RESOLVE => [$this->pin($uri->getHost(), $port, $addresses)],
                    ],
                ])
                    ->withHeaders([
                        'User-Agent' => $userAgent,
                        'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                        'Accept-Language' => 'en',
                    ])
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->get($url);
            } catch (ConnectionException) {
                throw new PageFetchFailed('The server did not answer in time, or refused the connection.');
            }

            $status = $response->status();
            $location = $response->header('Location');

            if ($status >= 300 && $status < 400 && $location !== '') {
                $redirects[] = ['url' => $url, 'status' => $status];

                try {
                    $url = self::normalise((string) UriResolver::resolve($uri, new Uri($location)));
                } catch (PageFetchFailed $refused) {
                    throw self::refusedRedirect($refused);
                } catch (Throwable) {
                    throw new PageFetchFailed('It redirects to an address that cannot be read.');
                }

                continue;
            }

            [$body, $truncated] = $this->read($response->toPsrResponse()->getBody());

            return new FetchedPage(
                requestedUrl: self::normalise($input),
                finalUrl: $url,
                status: $status,
                contentType: $response->header('Content-Type'),
                robotsHeader: $response->header('X-Robots-Tag'),
                redirects: $redirects,
                html: $body,
                bytes: strlen($body),
                truncated: $truncated,
                elapsedMs: (int) round((hrtime(true) - $started) / 1_000_000),
            );
        }

        throw new PageFetchFailed('It redirects more than '.self::MAX_REDIRECTS.' times, so it was not followed to the end.');
    }

    private static function refusedRedirect(PageFetchFailed $refused): PageFetchFailed
    {
        return new PageFetchFailed('It redirects somewhere that is not fetched: '.lcfirst($refused->getMessage()));
    }

    /**
     * Every address the host resolves to, refusing the lot if any is not public.
     *
     * @return non-empty-list<string>
     *
     * @throws PageFetchFailed
     */
    private function publicAddresses(string $host): array
    {
        $host = trim($host, '[]');
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new PageFetchFailed("The host {$host} does not resolve.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new PageFetchFailed('That address points into a private or reserved network, which is not fetched.');
            }
        }

        return $addresses;
    }

    /**
     * A curl `--resolve` entry: this host and port connect to these addresses only.
     *
     * @param  non-empty-list<string>  $addresses
     */
    private function pin(string $host, int $port, array $addresses): string
    {
        $listed = array_map(
            fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        );

        return trim($host, '[]').":{$port}:".implode(',', $listed);
    }

    /**
     * @return array{string, bool}
     */
    private function read(StreamInterface $stream): array
    {
        $body = '';

        while (! $stream->eof() && strlen($body) < self::MAX_BYTES) {
            $chunk = $stream->read(65_536);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        $truncated = strlen($body) > self::MAX_BYTES || ! $stream->eof();
        $stream->close();

        return [substr($body, 0, self::MAX_BYTES), $truncated];
    }
}
