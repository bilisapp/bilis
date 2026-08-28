<?php

/**
 * One image, three long-running roles.
 *
 * The role can be chosen by argument or by `BILIS_ROLE`, because the platforms
 * that run this disagree about which they offer: `docker run` overrides the
 * command, while Coolify's Dockerfile build pack gives you only the
 * environment. Both paths land on the same three processes, and the Dockerfile
 * carries no `CMD` so the environment variable is actually reachable.
 *
 * The script is exercised with stub `php` and `frankenphp` executables that
 * print what they were asked to run, in a throwaway working directory — the
 * entrypoint creates storage paths relative to the cwd.
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/bilis-entrypoint-'.bin2hex(random_bytes(6));
    mkdir($this->sandbox.'/bin', 0755, true);

    foreach (['php', 'frankenphp'] as $binary) {
        $path = $this->sandbox.'/bin/'.$binary;
        file_put_contents($path, "#!/bin/sh\necho \"{$binary} \$*\"\n");
        chmod($path, 0755);
    }
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->sandbox));
});

/**
 * Run the entrypoint and return its exit code, stdout and stderr.
 *
 * @param  array<string, string>  $env
 * @return array{code: int, out: string, err: string}
 */
function runEntrypoint(string $sandbox, array $env = [], string $args = ''): array
{
    $environment = array_merge([
        'PATH' => $sandbox.'/bin:/usr/bin:/bin',
        // The stub php would happily "run" it, but the point here is the role
        // that gets chosen, not the warm-up.
        'BILIS_OPTIMIZE_ON_STARTUP' => 'false',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $sandbox.'/database.sqlite',
    ], $env);

    $exports = '';
    foreach ($environment as $key => $value) {
        $exports .= sprintf('%s=%s ', $key, escapeshellarg($value));
    }

    $script = escapeshellarg(base_path('docker-entrypoint.sh'));
    $stderr = $sandbox.'/stderr';

    $command = sprintf(
        'cd %s && env %s sh %s %s 2>%s',
        escapeshellarg($sandbox),
        $exports,
        $script,
        $args,
        escapeshellarg($stderr),
    );

    exec($command, $output, $code);

    return [
        'code' => $code,
        'out' => implode("\n", $output),
        'err' => is_file($stderr) ? (string) file_get_contents($stderr) : '',
    ];
}

test('with no argument and no environment it serves the web role', function () {
    $result = runEntrypoint($this->sandbox);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('frankenphp run');
});

test('BILIS_ROLE selects the role when the platform cannot override the command', function (string $role, string $expected) {
    $result = runEntrypoint($this->sandbox, ['BILIS_ROLE' => $role]);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain($expected);
})->with([
    ['web', 'frankenphp run'],
    ['horizon', 'php artisan horizon'],
    ['scheduler', 'php artisan schedule:work'],
]);

test('an argument still wins over the environment', function () {
    // A one-off `docker run … artisan migrate` on a host whose environment
    // says `horizon` must run the migration, not start a queue worker.
    $result = runEntrypoint($this->sandbox, ['BILIS_ROLE' => 'horizon'], 'artisan migrate --force');

    expect($result['out'])->toContain('php artisan migrate --force')
        ->and($result['out'])->not->toContain('horizon');
});

/*
 * Flags ride with the ARGUMENT form. Bare argv cannot mean "flags for the role
 * in the environment" and "a command to run verbatim" at once, and the
 * passthrough is the older contract — so `BILIS_ROLE` takes the role and
 * nothing else.
 */
test('extra flags are passed through to the role named in the argument', function () {
    $result = runEntrypoint($this->sandbox, [], 'horizon --environment=production');

    expect($result['out'])->toContain('php artisan horizon --environment=production');
});

/*
 * The failure this is really about: `BILIS_ROLE=horzion` silently starting a
 * second web server. The only symptom would be a queue that never drains,
 * noticed hours later, with nothing in any log to explain it. Refusing to
 * start is the kinder answer.
 */
test('a misspelled role refuses to start rather than quietly serving web', function () {
    $result = runEntrypoint($this->sandbox, ['BILIS_ROLE' => 'horzion']);

    expect($result['code'])->toBe(64)
        ->and($result['err'])->toContain('horzion')
        ->and($result['out'])->not->toContain('frankenphp');
});

test('a command that is not a role is run verbatim', function () {
    $result = runEntrypoint($this->sandbox, ['BILIS_ROLE' => 'horizon'], 'php --version');

    expect($result['out'])->toContain('php --version');
});

test('the storage tree and sqlite database are prepared before the role starts', function () {
    runEntrypoint($this->sandbox, ['BILIS_ROLE' => 'scheduler']);

    expect(is_dir($this->sandbox.'/storage/framework/sessions'))->toBeTrue()
        ->and(is_dir($this->sandbox.'/bootstrap/cache'))->toBeTrue()
        ->and(is_file($this->sandbox.'/database.sqlite'))->toBeTrue();
});

/*
 * The Dockerfile must not carry a CMD: it would occupy the argv slot on every
 * run and make BILIS_ROLE unreachable on exactly the platforms it exists for.
 */
test('the image ships no default command for a CMD to shadow the role', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('CMD []')
        ->and($dockerfile)->not->toContain('CMD ["web"]');
});
