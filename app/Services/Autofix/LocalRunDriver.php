<?php

namespace App\Services\Autofix;

use Illuminate\Support\Facades\File;

/**
 * Runs Ayos as a child process on this machine.
 *
 * This is local development, and it is a faithful rehearsal rather than a
 * simulation: the runner reads `AYOS_JOB_SPEC` from its environment, clones,
 * runs the agent, and posts a signed artifact back over HTTP — exactly what it
 * does inside a container. The only thing this driver replaces is the platform
 * API call that would otherwise start it.
 *
 * The spec goes in through the environment and never onto the command line:
 * argv is world-readable through `ps`, and the spec is a bundle of live
 * credentials.
 */
class LocalRunDriver implements RunDriver
{
    /**
     * Start the runner detached, and return its pid as the run id.
     *
     * Detached on purpose, and detached properly. The web request that
     * dispatched this job must not wait fifteen minutes for an agent, and the
     * run has to outlive the queue worker that started it — it reports back
     * over HTTP, so nothing waits on its exit code except reconciliation.
     *
     * That is why this goes through a shell rather than spawning node
     * directly: PHP closes a `proc_open` handle when the request ends and
     * BLOCKS until the child exits, which would tie every run to the lifetime
     * of the worker that started it. Backgrounding inside a shell that then
     * exits re-parents the runner away from PHP entirely, and `echo $!` hands
     * back the pid that was lost by doing so.
     *
     * @throws AyosException
     */
    public function start(string $spec, string $jobId): string
    {
        $entrypoint = (string) config('autofix.runner.local.entrypoint');

        if ($entrypoint === '' || ! is_file($entrypoint)) {
            throw AyosException::missingConfiguration(
                'autofix.runner.local.entrypoint (build ayos first: pnpm build)',
            );
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['sh', '-c', 'nohup "$AYOS_NODE" "$AYOS_ENTRYPOINT" >> "$AYOS_LOG" 2>&1 & echo $!'],
            $descriptors,
            $pipes,
            dirname($entrypoint),
            [
                /*
                 * A deliberately narrow environment. The runner needs a PATH to
                 * find git and nothing else from this process — least of all
                 * Bilis's own secrets, which would otherwise be inherited
                 * wholesale into the container the agent's `bash` runs in.
                 *
                 * The spec travels here rather than on the command line: argv
                 * is world-readable through `ps`, and the spec is a bundle of
                 * live credentials.
                 */
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
                'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
                'AYOS_NODE' => (string) config('autofix.runner.local.node', 'node'),
                'AYOS_ENTRYPOINT' => $entrypoint,
                'AYOS_LOG' => $this->logPath($jobId),
                'AYOS_JOB_SPEC' => $spec,
            ],
        );

        if (! is_resource($process)) {
            throw AyosException::runnerUnavailable('the local runner process could not be started');
        }

        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        $stderr = trim((string) stream_get_contents($pipes[2]));

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        // The SHELL has already exited by now — the runner it left behind has
        // not — so this returns immediately.
        proc_close($process);

        if ($pid <= 0) {
            throw AyosException::runnerUnavailable(
                'the local runner reported no pid'.($stderr !== '' ? ': '.$stderr : ''),
            );
        }

        return (string) $pid;
    }

    /**
     * Signal the run to stop.
     *
     * SIGTERM, not SIGKILL: the runner treats it as a cancellation, aborts the
     * agent and still tries to deliver a `cancelled` artifact. Killing it
     * outright would leave the caller to infer the outcome.
     */
    public function stop(string $runId): void
    {
        $pid = (int) $runId;

        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return;
        }

        // A run that has already exited is not an error: there is nothing left
        // to abort, which is what cancellation wanted.
        @posix_kill($pid, SIGTERM);
    }

    /**
     * Whether the process is still alive.
     */
    public function status(string $runId): ?RunStatus
    {
        $pid = (int) $runId;

        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return null;
        }

        // Signal 0 performs the permission and existence checks without
        // sending anything.
        return @posix_kill($pid, 0) ? RunStatus::Running : RunStatus::Finished;
    }

    /**
     * Where this run's stdout and stderr land.
     *
     * One file per job, kept: it holds the phase transitions and the delivery
     * outcome, which is the first thing to read when a job goes quiet. It never
     * holds the transcript or a credential — the runner logs neither.
     */
    protected function logPath(string $jobId): string
    {
        $dir = (string) config('autofix.runner.local.log_path', storage_path('logs/ayos-runs'));

        File::ensureDirectoryExists($dir);

        return $dir.'/'.$jobId.'.log';
    }
}
