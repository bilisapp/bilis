<?php

/**
 * The healthcheck has to mean something different in each role.
 *
 * Only the web role opens a port, so the old `GET /up` probe could never pass
 * inside the horizon or scheduler containers: three failed attempts, an empty
 * healthcheck log, and the platform rolling the deployment back on a process
 * that was in fact running fine.
 *
 * Exercised with stub `php` and `curl` executables whose exit codes are
 * dictated by the environment, so no server or Redis is needed.
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/bilis-healthcheck-'.bin2hex(random_bytes(6));
    mkdir($this->sandbox.'/bin', 0755, true);

    foreach (['php' => 'STUB_PHP_CODE', 'curl' => 'STUB_CURL_CODE'] as $binary => $variable) {
        $path = $this->sandbox.'/bin/'.$binary;
        file_put_contents($path, "#!/bin/sh\necho \"{$binary} \$*\" >> \"\$STUB_LOG\"\nexit \"\${{$variable}:-0}\"\n");
        chmod($path, 0755);
    }
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->sandbox));
});

/**
 * Run the healthcheck and return its exit code plus what it invoked.
 *
 * @param  array<string, string>  $env
 * @return array{code: int, calls: string}
 */
function runHealthcheck(string $sandbox, array $env = []): array
{
    $log = $sandbox.'/calls';

    $environment = array_merge([
        'PATH' => $sandbox.'/bin:/usr/bin:/bin',
        'STUB_LOG' => $log,
        'BILIS_ROLE_FILE' => $sandbox.'/role',
    ], $env);

    $exports = '';
    foreach ($environment as $key => $value) {
        $exports .= sprintf('%s=%s ', $key, escapeshellarg($value));
    }

    $command = sprintf(
        'cd %s && env %s sh %s >/dev/null 2>&1',
        escapeshellarg($sandbox),
        $exports,
        escapeshellarg(base_path('docker-healthcheck.sh')),
    );

    exec($command, $output, $code);

    return [
        'code' => $code,
        'calls' => is_file($log) ? (string) file_get_contents($log) : '',
    ];
}

test('the entrypoint records the role it resolved for the healthcheck to read', function (string $args, array $env, string $expected) {
    $roleFile = $this->sandbox.'/role';

    runEntrypoint($this->sandbox, array_merge($env, ['BILIS_ROLE_FILE' => $roleFile]), $args);

    expect((string) file_get_contents($roleFile))->toBe($expected);
})->with([
    'argument' => ['horizon', [], 'horizon'],
    'environment' => ['', ['BILIS_ROLE' => 'scheduler'], 'scheduler'],
    'default' => ['', [], 'web'],
]);

test('the web role is healthy only while it answers /up', function (string $curlCode, int $expected) {
    file_put_contents($this->sandbox.'/role', 'web');

    $result = runHealthcheck($this->sandbox, ['STUB_CURL_CODE' => $curlCode]);

    expect($result['code'])->toBe($expected)
        ->and($result['calls'])->toContain('127.0.0.1:8080/up');
})->with([
    'answering' => ['0', 0],
    'refusing' => ['7', 1],
]);

test('the web role probes the port it was told to serve', function () {
    file_put_contents($this->sandbox.'/role', 'web');

    $result = runHealthcheck($this->sandbox, ['PORT' => '9001']);

    expect($result['calls'])->toContain('127.0.0.1:9001/up');
});

/*
 * `horizon:status` exits 0 running, 1 paused, 2 inactive. A pause is an
 * operator's deliberate act — restarting the container would silently undo it,
 * so only "inactive" counts as a failure.
 */
test('the horizon role is checked by its own status, not by HTTP', function (string $statusCode, int $expected) {
    file_put_contents($this->sandbox.'/role', 'horizon');

    $result = runHealthcheck($this->sandbox, ['STUB_PHP_CODE' => $statusCode]);

    expect($result['code'])->toBe($expected)
        ->and($result['calls'])->toContain('artisan horizon:status')
        ->and($result['calls'])->not->toContain('curl');
})->with([
    'running' => ['0', 0],
    'paused' => ['1', 0],
    'inactive' => ['2', 1],
]);

test('the scheduler role is checked by booting the framework, not by HTTP', function () {
    file_put_contents($this->sandbox.'/role', 'scheduler');

    $result = runHealthcheck($this->sandbox);

    expect($result['code'])->toBe(0)
        ->and($result['calls'])->toContain('artisan schedule:list')
        ->and($result['calls'])->not->toContain('curl');
});

/*
 * A one-off `docker run bilis artisan migrate` is not a service. The
 * entrypoint records an empty role for it, and a probe has nothing to report.
 */
test('a verbatim command reports no health rather than failing', function () {
    file_put_contents($this->sandbox.'/role', '');

    $result = runHealthcheck($this->sandbox);

    expect($result['code'])->toBe(0)
        ->and($result['calls'])->toBe('');
});

/*
 * The window between the container starting and the entrypoint writing the
 * file: the environment still names the role in the deployment that matters.
 */
test('without a role file it falls back to BILIS_ROLE', function () {
    $result = runHealthcheck($this->sandbox, ['BILIS_ROLE' => 'horizon']);

    expect($result['calls'])->toContain('artisan horizon:status');
});

test('the image runs the role-aware healthcheck rather than probing /up blindly', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('CMD ["bilis-healthcheck"]')
        ->and($dockerfile)->toContain('/usr/local/bin/bilis-healthcheck');
});
