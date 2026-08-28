<?php

namespace App\Services\Autofix;

/**
 * Starts and stops one Ayos run.
 *
 * A run is a container that boots with its job spec in the environment, does
 * the work, posts its results back and exits. It has no inbound HTTP, so this
 * interface is the entire control surface: start it, stop it, ask whether it is
 * still alive. There is nothing to call mid-flight.
 *
 * `LocalRunDriver` spawns a child process on this machine; `ScalewayRunDriver`
 * starts a Serverless Job run. Everything downstream — the signed callback, the
 * event batches, the artifact — is identical either way, which is what makes
 * "it worked locally" mean something.
 */
interface RunDriver
{
    /**
     * Start a run with `$spec` as its job spec, and return the run's id.
     *
     * The id is the only handle on the run afterwards: it is what `stop()`
     * takes, and what `status()` asks about when a run goes quiet.
     *
     * @throws AyosException
     */
    public function start(string $spec, string $jobId): string;

    /**
     * Ask a run to stop. Idempotent, and a run that is already gone is a
     * success — there is nothing left to abort.
     *
     * @throws AyosException
     */
    public function stop(string $runId): void;

    /**
     * What the platform believes about this run.
     *
     * `null` means "no answer" — the driver could not tell, which is not the
     * same as the run being dead and must not be reconciled as one.
     *
     * @throws AyosException
     */
    public function status(string $runId): ?RunStatus;
}
