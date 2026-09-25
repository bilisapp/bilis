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
