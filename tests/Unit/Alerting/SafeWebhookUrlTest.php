<?php

use App\Services\Alerting\Webhooks\DnsWebhookHostResolver;
use App\Services\Alerting\Webhooks\ResolvedWebhookTarget;
use App\Services\Alerting\Webhooks\SafeWebhookUrl;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use App\Services\Alerting\Webhooks\WebhookHostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A resolver that answers from a script: one list of addresses per lookup,
 * the last one repeated, and a count of how often it was asked.
 *
 * @param  list<list<string>>  $answers
 */
function fakeDns(array ...$answers): WebhookHostResolver
{
    return new class($answers) implements WebhookHostResolver
    {
        public int $lookups = 0;

        /** @param  list<list<string>>  $answers */
        public function __construct(private array $answers) {}

        public function resolve(string $host): array
        {
            return $this->answers[min($this->lookups++, count($this->answers) - 1)];
        }
    };
}

function guard(array $addresses = ['93.184.215.14'], bool $allowHttp = false): SafeWebhookUrl
{
    return new SafeWebhookUrl(fakeDns($addresses), $allowHttp);
}

function refusal(callable $call): ?string
{
    try {
        $call();
    } catch (UnsafeWebhookUrl $refused) {
        return $refused->getMessage();
    }

    return null;
}

dataset('blocked addresses', [
    // IPv4
    'this network' => '0.0.0.0',
    'this network, any' => '0.1.2.3',
    'RFC1918 10/8' => '10.0.0.1',
    'CGNAT 100.64/10 low' => '100.64.0.1',
    'CGNAT 100.64/10 high' => '100.127.255.254',
    'loopback' => '127.0.0.1',
    'loopback, any' => '127.8.9.10',
    'link-local' => '169.254.1.1',
    'cloud metadata' => '169.254.169.254',
    'RFC1918 172.16/12 low' => '172.16.0.1',
    'RFC1918 172.16/12 high' => '172.31.255.255',
    'IETF 192.0.0/24' => '192.0.0.1',
    'TEST-NET-1' => '192.0.2.1',
    '6to4 relay' => '192.88.99.1',
    'RFC1918 192.168/16' => '192.168.1.1',
    'benchmarking 198.18/15' => '198.19.255.1',
    'TEST-NET-2' => '198.51.100.1',
    'TEST-NET-3' => '203.0.113.1',
    'multicast low' => '224.0.0.1',
    'multicast high' => '239.255.255.250',
    'reserved 240/4' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
    // IPv6
    'unspecified' => '::',
    'v6 loopback' => '::1',
    'discard 100::/64' => '100::1',
    'unique local fc00::/7' => 'fc00::1',
    'unique local fd' => 'fd12:3456::1',
    'AWS metadata v6' => 'fd00:ec2::254',
    'v6 link-local' => 'fe80::1',
    'v6 site-local' => 'fec0::1',
    'v6 multicast' => 'ff02::1',
    'v6 global multicast' => 'ff0e::1',
    'documentation 2001:db8' => '2001:db8::1',
    'Teredo' => '2001:0:4136:e378::1',
    '6to4' => '2002:c0a8:101::1',
    'local-use NAT64' => '64:ff9b:1::a00:1',
    // IPv6 forms carrying a blocked IPv4 address
    'mapped loopback' => '::ffff:127.0.0.1',
    'mapped loopback, hex' => '::ffff:7f00:1',
    'mapped metadata' => '::ffff:169.254.169.254',
    'mapped private' => '::ffff:10.0.0.1',
    'mapped CGNAT' => '::ffff:100.64.0.1',
    'compatible loopback' => '::127.0.0.1',
    'compatible private' => '::a00:1',
    'SIIT loopback' => '::ffff:0:7f00:1',
    'NAT64 loopback' => '64:ff9b::127.0.0.1',
    'NAT64 metadata' => '64:ff9b::a9fe:a9fe',
    'NAT64 CGNAT' => '64:ff9b::6440:1',
]);

dataset('public addresses', [
    'v4' => '93.184.215.14',
    'v4 next to CGNAT' => '100.128.0.1',
    'v4 next to 172.16/12' => '172.32.0.1',
    'v6' => '2606:4700:4700::1111',
    'v6 google' => '2001:4860:4860::8888',
    'NAT64 of a public v4' => '64:ff9b::808:808',
]);

it('refuses every non-public address', function (string $ip) {
    expect(SafeWebhookUrl::isPublicAddress($ip))->toBeFalse();
})->with('blocked addresses');

it('accepts public addresses', function (string $ip) {
    expect(SafeWebhookUrl::isPublicAddress($ip))->toBeTrue();
})->with('public addresses');

it('refuses a host when any one of its addresses is not public', function (string $ip) {
    $message = refusal(fn () => guard(['93.184.215.14', $ip])->resolve('https://hooks.example.com/x'));

    expect($message)->toBe('The host hooks.example.com resolves to a private or reserved address, which webhooks cannot be sent to.');
})->with('blocked addresses');

it('refuses IP-literal URLs in a blocked range without asking DNS', function (string $ip) {
    $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;

    expect(refusal(fn () => guard()->validate("https://{$host}/hook")))
        ->toBe('The URL points at a private or reserved address, which webhooks cannot be sent to.');
})->with('blocked addresses');

it('refuses IPv4 written in decimal, octal, hex or short form', function (string $host) {
    expect(refusal(fn () => guard()->validate("https://{$host}/hook")))
        ->toBe('Write an IP address as four decimal numbers, like 203.0.113.7.');
})->with([
    'decimal' => '2130706433',
    'hex' => '0x7f000001',
    'dotted hex' => '0x7f.0.0.1',
    'octal' => '0177.0.0.1',
    'leading zero' => '127.000.000.001',
    'short' => '127.1',
    'metadata, decimal' => '2852039166',
    'trailing dot' => '2130706433.',
]);

it('refuses other malformed or local hosts', function (string $url, string $message) {
    expect(refusal(fn () => guard()->validate($url)))->toBe($message);
})->with([
    'localhost' => ['https://localhost/hook', 'Webhooks cannot be sent to localhost.'],
    'sub.localhost' => ['https://api.localhost/hook', 'Webhooks cannot be sent to localhost.'],
    'zone id' => ['https://[fe80::1%25eth0]/hook', 'The URL contains an IPv6 address that cannot be read.'],
    'unicode host' => ['https://bücher.example/hook', 'The URL host is not a valid host name; write international names in their xn-- form.'],
    'backslash trick' => ['https://example.com\\@127.0.0.1/', 'The URL contains spaces, backslashes or control characters.'],
    'space' => ['https://example.com /hook', 'The URL contains spaces, backslashes or control characters.'],
    'newline' => ["https://example.com/\nhook", 'The URL contains spaces, backslashes or control characters.'],
    'relative' => ['/hooks/abc', 'Enter a full URL, starting with https://.'],
    'no scheme' => ['example.com/hook', 'Enter a full URL, starting with https://.'],
]);

it('requires https unless http is explicitly allowed', function () {
    expect(refusal(fn () => guard()->validate('http://hooks.example.com/x')))->toBe('The URL must start with https://.')
        ->and(refusal(fn () => guard()->validate('ftp://hooks.example.com/x')))->toBe('The URL must start with https://.')
        ->and(refusal(fn () => guard()->validate('gopher://hooks.example.com/x')))->toBe('The URL must start with https://.')
        ->and(refusal(fn () => guard(allowHttp: true)->validate('http://hooks.example.com/x')))->toBeNull()
        ->and(refusal(fn () => guard(allowHttp: true)->validate('file:///etc/passwd')))->not->toBeNull();
});

it('allows http by default only in the local and testing environments', function () {
    expect(refusal(fn () => SafeWebhookUrl::forEnvironment()->validate('http://hooks.example.com/x')))->toBeNull();

    app()->detectEnvironment(fn () => 'production');

    expect(refusal(fn () => SafeWebhookUrl::forEnvironment()->validate('http://hooks.example.com/x')))->toBe('The URL must start with https://.');
});

it('refuses user info and non-default ports', function () {
    expect(refusal(fn () => guard()->validate('https://user:pass@hooks.example.com/x')))->toBe('The URL must not contain a user name or password.')
        ->and(refusal(fn () => guard()->validate('https://hooks.example.com@127.0.0.1/x')))->toBe('The URL must not contain a user name or password.')
        ->and(refusal(fn () => guard()->validate('https://hooks.example.com:8123/x')))->toBe('The URL must use the default port (443).')
        ->and(refusal(fn () => guard(allowHttp: true)->validate('http://hooks.example.com:443/x')))->toBe('The URL must use the default port (80).')
        ->and(refusal(fn () => guard()->validate('https://hooks.example.com:443/x')))->toBeNull();
});

it('requires the exact Slack host when asked to', function () {
    $slack = 'https://hooks.slack.com/services/T000/B000/XXXX';

    expect(refusal(fn () => guard()->validate($slack, 'hooks.slack.com')))->toBeNull()
        ->and(refusal(fn () => guard()->validate('https://HOOKS.Slack.com./services/T0/B0/X', 'hooks.slack.com')))->toBeNull()
        ->and(refusal(fn () => guard()->validate('https://hooks.slack.com.evil.example/services/x', 'hooks.slack.com')))->toBe('The URL must be on hooks.slack.com.')
        ->and(refusal(fn () => guard()->validate('https://evil-hooks.slack.com/services/x', 'hooks.slack.com')))->toBe('The URL must be on hooks.slack.com.')
        ->and(refusal(fn () => guard()->resolve($slack, 'hooks.slack.com')))->toBeNull();
});

it('refuses a host that does not resolve', function () {
    expect(refusal(fn () => guard([])->resolve('https://nowhere.example/x')))->toBe('The host nowhere.example does not resolve to an address.');
});

it('resolves to a target pinned to the first checked address, with the URL rebuilt', function () {
    $target = guard(['93.184.215.14', '2606:4700:4700::1111'])->resolve('https://Hooks.Example.com:443/a/b?c=d#frag');

    expect($target->url)->toBe('https://hooks.example.com/a/b?c=d')
        ->and($target->host)->toBe('hooks.example.com')
        ->and($target->port)->toBe(443)
        ->and($target->ip)->toBe('93.184.215.14')
        ->and($target->curlResolveEntry())->toBe('hooks.example.com:443:93.184.215.14');

    $v6 = guard(['2606:4700:4700::1111'])->resolve('https://hooks.example.com');

    expect($v6->url)->toBe('https://hooks.example.com/')
        ->and($v6->curlResolveEntry())->toBe('hooks.example.com:443:[2606:4700:4700::1111]');
});

it('passes public IP literals through without DNS or a resolve entry', function () {
    $dns = fakeDns(['10.0.0.1']);
    $target = (new SafeWebhookUrl($dns))->resolve('https://[2606:4700:4700::1111]/hook');

    expect($target->url)->toBe('https://[2606:4700:4700::1111]/hook')
        ->and($target->ip)->toBe('2606:4700:4700::1111')
        ->and($target->curlResolveEntry())->toBeNull()
        ->and($dns->lookups)->toBe(0);
});

it('sends to the address it checked even if DNS rebinds afterwards', function () {
    $dns = fakeDns(['93.184.215.14'], ['127.0.0.1']);
    $guard = new SafeWebhookUrl($dns);
    $sent = [];

    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = $options;

        return Http::response('ok');
    });

    $target = $guard->resolve('https://rebind.example/hook');
    $target->pendingRequest()->post($target->url, ['a' => 1]);

    expect($dns->lookups)->toBe(1)
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['curl'][CURLOPT_RESOLVE])->toBe(['rebind.example:443:93.184.215.14'])
        ->and($sent[0]['allow_redirects'])->toBeFalse()
        ->and($sent[0]['proxy'])->toBe('')
        ->and($sent[0]['curl'][CURLOPT_PROXY])->toBe('')
        ->and($sent[0]['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($sent[0]['connect_timeout'])->toBe(5)
        ->and($sent[0]['timeout'])->toBe(5);

    // The next send resolves afresh and sees the rebound answer.
    expect(refusal(fn () => $guard->resolve('https://rebind.example/hook')))->not->toBeNull();
});

it('refuses to build a target pinned to anything but a public address', function (string $ip) {
    expect(refusal(fn () => new ResolvedWebhookTarget('https://hooks.example.com/', 'hooks.example.com', 443, $ip)))
        ->toBe('A webhook target must be pinned to a public address.');
})->with(['', '127.0.0.1', '10.0.0.5', '169.254.169.254', '::1', 'not-an-ip']);

it('does not follow a redirect', function () {
    Http::fake([
        'hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('secret'),
    ]);

    $target = guard()->resolve('https://hooks.example.com/hook');
    $response = $target->pendingRequest()->post($target->url, ['a' => 1]);

    expect($response->status())->toBe(302);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
});

it('binds the DNS resolver for the container', function () {
    expect(app(WebhookHostResolver::class))->toBeInstanceOf(DnsWebhookHostResolver::class)
        ->and(app(SafeWebhookUrl::class))->toBeInstanceOf(SafeWebhookUrl::class);
});
