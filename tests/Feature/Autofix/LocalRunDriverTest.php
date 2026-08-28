<?php

use App\Services\Autofix\AyosException;
use App\Services\Autofix\LocalRunDriver;
use App\Services\Autofix\RunStatus;
use Illuminate\Support\Facades\File;

/**
 * The driver that starts a run on this machine.
 *
 * These use a stub script rather than the real runner: the behaviour under test
 * is the spawning — that the spec reaches the child through its environment
 * rather than its argv, that the child outlives the PHP process that started
 * it, and that the pid coming back is the runner's rather than the shell's.
 */
function stubRunner(string $body): string
{
    // The driver hands the child a deliberately NARROW environment, so a stub
    // cannot be told where to write through one — the path is baked in.
    $dir = sys_get_temp_dir().'/ayos-driver-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir);

    $path = $dir.'/entry.js';
    File::put($path, $body);

    config([
        'autofix.runner.local.entrypoint' => $path,
        'autofix.runner.local.node' => (string) (getenv('AYOS_TEST_NODE') ?: 'node'),
        'autofix.runner.local.log_path' => $dir.'/logs',
    ]);

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/ayos-driver-test-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

test('the spec reaches the run through its environment, never its argv', function () {
    $dir = stubRunner('const fs = require("node:fs");');
    $out = $dir.'/captured.json';

    File::put($dir.'/entry.js', 'const fs = require("node:fs");'
        .'fs.writeFileSync('.json_encode($out).', JSON.stringify({'
        .'  spec: process.env.AYOS_JOB_SPEC,'
        .'  argv: process.argv.slice(1),'
        .'}));');

    $spec = json_encode(['job_id' => 'abc', 'clone_token' => 'ghs_secret_value']);

    (new LocalRunDriver)->start((string) $spec, 'abc');

    // The child writes and exits; give it a moment rather than a fixed sleep.
    $deadline = microtime(true) + 10;
    while (! is_file($out) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect(is_file($out))->toBeTrue();

    $captured = json_decode((string) file_get_contents($out), true);

    expect($captured['spec'])->toBe($spec);

    /*
     * argv is world-readable through `ps`, and the spec is a bundle of live
     * credentials — a clone token, an LLM key and this run's signing key.
     */
    expect(implode(' ', $captured['argv']))->not->toContain('ghs_secret_value');
})->skip(fn (): bool => ! commandExists('node'), 'node is not installed');

test('the run outlives the process that started it', function () {
    stubRunner('setTimeout(() => {}, 5000);');

    $pid = (new LocalRunDriver)->start('{}', 'abc');

    // If PHP were still holding the child, `proc_close` would have blocked for
    // five seconds before start() returned — and the process would be gone.
    expect((new LocalRunDriver)->status($pid))->toBe(RunStatus::Running);

    posix_kill((int) $pid, SIGKILL);
})->skip(fn (): bool => ! commandExists('node'), 'node is not installed');

test('stopping a run signals it', function () {
    $dir = stubRunner('');
    $marker = $dir.'/terminated.txt';

    File::put($dir.'/entry.js', 'const fs = require("node:fs");'
        .'process.on("SIGTERM", () => {'
        .'  fs.writeFileSync('.json_encode($marker).', "terminated");'
        .'  process.exit(0);'
        .'});'
        .'setTimeout(() => {}, 30000);');

    $driver = new LocalRunDriver;
    $pid = $driver->start('{}', 'abc');

    // SIGTERM rather than SIGKILL: the runner treats it as a cancellation and
    // still tries to deliver a `cancelled` artifact.
    usleep(500_000);
    $driver->stop($pid);

    $deadline = microtime(true) + 10;
    while (! is_file($marker) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect(is_file($marker))->toBeTrue();
})->skip(fn (): bool => ! commandExists('node'), 'node is not installed');

test('a run that has already exited reports finished and stops without error', function () {
    stubRunner('process.exit(0);');

    $driver = new LocalRunDriver;
    $pid = $driver->start('{}', 'abc');

    $deadline = microtime(true) + 10;
    while ($driver->status($pid) === RunStatus::Running && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect($driver->status($pid))->toBe(RunStatus::Finished);

    $driver->stop($pid);
})->throwsNoExceptions()->skip(fn (): bool => ! commandExists('node'), 'node is not installed');

test('a missing entrypoint fails with a message that says how to fix it', function () {
    config(['autofix.runner.local.entrypoint' => '/nowhere/entry.js']);

    expect(fn () => (new LocalRunDriver)->start('{}', 'abc'))
        ->toThrow(AyosException::class, 'pnpm build');
});

test('the run writes its output to a per-job log', function () {
    $dir = stubRunner('console.log("phase: cloning");');

    (new LocalRunDriver)->start('{}', 'job-uuid');

    $log = $dir.'/logs/job-uuid.log';

    // The shell creates the file the instant it redirects into it, so waiting
    // for the file to exist would race the process that fills it.
    $deadline = microtime(true) + 10;
    while (@file_get_contents($log) === '' && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect(file_get_contents($log))->toContain('phase: cloning');
})->skip(fn (): bool => ! commandExists('node'), 'node is not installed');
