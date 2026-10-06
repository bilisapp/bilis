<?php

use Symfony\Component\Process\Process;

/**
 * The installer as Bilis serves it.
 */
function servedInstaller(): string
{
    return (string) test()->get('/install.sh')->assertOk()->getContent();
}

test('the installer is served as plain text with this instance as its origin', function () {
    config(['app.url' => 'https://bilis.example.com/']);

    $response = $this->get('/install.sh');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())
        ->toStartWith('#!/bin/sh')
        ->toContain("BILIS_ORIGIN='https://bilis.example.com'")
        ->toContain('curl -fsSL https://bilis.example.com/install.sh')
        ->not->toContain('__BILIS_');
});

test('the origin comes from config, never from the request', function () {
    config(['app.url' => 'https://bilis.example.com']);

    $content = (string) $this->withHeader('Host', 'evil.test')
        ->get('/install.sh?origin=https://evil.test')
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain('evil.test');
});

test('the collector it installs is pinned by version and checksum for both architectures', function () {
    expect(servedInstaller())
        ->toContain("OTELCOL_VERSION='0.159.0'")
        ->toContain("OTELCOL_SHA256_AMD64='9d589f6349f01179957a2052bc7307a99db2efc971e14e00575941a77122eaaf'")
        ->toContain("OTELCOL_SHA256_ARM64='abb8665cc963e886c2d1286c50b38bcb2e53d968b192c3d8fe4d1ed6b91c3901'")
        ->toContain('[ "$actual" = "$SHA256" ] || fail');
});

test('the key only ever lands in a root-only env file', function () {
    $script = servedInstaller();

    expect($script)
        ->toContain('install -m 0600 -o root -g root "$TMP/agent.env" "$ENV_FILE"')
        ->toContain('Authorization: Bearer ${env:BILIS_API_KEY}')
        ->toContain('EnvironmentFile=$ENV_FILE')
        // Never on a command line where `ps` would show it.
        ->not->toContain('--key="$API_KEY"')
        ->not->toContain('ExecStart=$BINARY --key');
});

test('the served installer is valid POSIX shell', function () {
    $path = tempnam(sys_get_temp_dir(), 'bilis-install-');
    file_put_contents($path, servedInstaller());

    $process = new Process(['sh', '-n', $path]);
    $process->run();

    unlink($path);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

test('the installer is rate limited', function () {
    foreach (range(1, 60) as $ignored) {
        $this->get('/install.sh')->assertOk();
    }

    $this->get('/install.sh')->assertTooManyRequests();
});

/**
 * Run the installer's own `valid_check_url` against one URL.
 */
function checkUrlIsAccepted(string $url): bool
{
    preg_match('/^valid_check_url\(\) \{.*?^\}$/ms', servedInstaller(), $function);

    $process = new Process(['sh', '-c', $function[0]."\n".'valid_check_url "$1"', 'sh', $url]);
    $process->run();

    return $process->getExitCode() === 0;
}

test('check URLs that are safe to write into the config are accepted', function (string $url) {
    expect(checkUrlIsAccepted($url))->toBeTrue();
})->with([
    'http://localhost:8080/health',
    'https://example.com/a?b=c&d=e#section',
    'http://[::1]:8080/up',
    'https://mastodon.social/@someone',
]);

test('check URLs that could break out of the config, expand a secret or carry credentials are refused', function (string $url) {
    expect(checkUrlIsAccepted($url))->toBeFalse();
})->with([
    'not http' => 'ftp://example.com/',
    'no host' => 'http://',
    'no scheme' => 'localhost:8080',
    'credentials' => 'https://user:secret@example.com/',
    'collector expansion' => 'https://example.com/${env:BILIS_API_KEY}',
    'double quote' => 'https://example.com/"x',
    'single quote' => "https://example.com/'x",
    'space' => 'https://example.com/a b',
    'backtick' => 'https://example.com/`id`',
    'backslash' => 'https://example.com/\\x',
    'braces' => 'https://example.com/{x}',
]);

test('checks run in their own pipeline as service uptime, without the zero-valued status classes', function () {
    expect(servedInstaller())
        ->toContain('--check URL')
        ->toContain('http_check:')
        ->toContain("'metric.name == \"httpcheck.status\" and value_int == 0'")
        ->toContain('processors: [memory_limiter, filter/uptime, resource_detection, resource/uptime, batch]')
        // Re-runs keep the URLs from their own file, never from agent.env.
        ->toContain('CHECKS="$(tr \'\n\' \' \' <"$CHECKS_FILE")"');
});

test('the host scraper sends the utilization gauges the Hosts tab charts as percentages', function () {
    expect(servedInstaller())
        ->toContain("system.cpu.utilization:\n                        enabled: true")
        ->toContain("system.memory.utilization:\n                        enabled: true")
        ->toContain("system.filesystem.utilization:\n                        enabled: true")
        ->toContain('(Metrics -> Hosts)');
});
