<?php

namespace App\Services\Autofix;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Starts one Ayos run as a Scaleway Serverless Job run.
 *
 * This is the whole of the production control plane. There is no Ayos service
 * to deploy, no domain, no shared secret and no health check — a job is an
 * image, a job definition, and one API call per run.
 *
 * One caveat the API forces on us: `POST …/start` accepts only PLAIN
 * `environment_variables`. There is no per-run secret channel — Scaleway's
 * secret references live on the job definition and point at a static Secret
 * Manager entry, which by definition cannot vary per job. So the spec is
 * readable from the console and the API for the life of the run record, and
 * everything in it has to be short-lived enough for that to be acceptable.
 * See DEPLOY.md for what that means in practice.
 */
class ScalewayRunDriver implements RunDriver
{
    /**
     * How long a control-plane call to Scaleway may take.
     */
    public const TIMEOUT_SECONDS = 15;

    /**
     * The API version this driver speaks.
     *
     * Pinned, and worth pinning: the paths moved between alpha versions, and a
     * wrong one fails as a 404 that reads exactly like a deleted job.
     */
    public const API_VERSION = 'v1alpha2';

    /**
     * Scaleway's run states, reduced to the one question Bilis asks: could this
     * run still send me an artifact?
     *
     * Anything unrecognised is treated as ALIVE. A state Scaleway adds later
     * must not cause a healthy run to be reconciled as dead — the reaper's
     * deadline is the backstop for anything this map cannot classify.
     *
     * @var array<string, RunStatus>
     */
    private const STATES = [
        'unknown_state' => RunStatus::Running,
        'initialized' => RunStatus::Queued,
        'validated' => RunStatus::Queued,
        'queued' => RunStatus::Queued,
        'running' => RunStatus::Running,
        'retrying' => RunStatus::Running,
        'interrupting' => RunStatus::Running,
        'succeeded' => RunStatus::Finished,
        'failed' => RunStatus::Finished,
        'interrupted' => RunStatus::Finished,
    ];

    /**
     * Start a run and return its id.
     *
     * @throws AyosException
     */
    public function start(string $spec, string $jobId): string
    {
        /*
         * `environment_variables` is a plain map, and it is the ONLY per-run
         * channel the API offers — `POST …/start` has no secret equivalent.
         * Secret references exist, but they are attached to a job DEFINITION
         * and point at a static Secret Manager entry, so they cannot carry
         * anything that differs per job.
         *
         * Everything Ayos needs varies per job, the model credential included:
         * a customer's scoped, budgeted LLM token is not the same for every
         * job, so a definition-level secret reference cannot carry it either.
         * The whole spec therefore travels here in plain sight, and the safety
         * argument is scope rather than secrecy — the clone token is revoked
         * seconds into the run, the signing key authenticates one job that is
         * over in minutes, and the LLM token is capped at one customer's
         * budget. See DEPLOY.md §2.
         */
        $response = $this->send(
            'post',
            sprintf('job-definitions/%s/start', $this->definitionId()),
            ['environment_variables' => ['AYOS_JOB_SPEC' => $spec]],
        );

        $runId = $response['id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            throw AyosException::runnerUnavailable('Scaleway accepted the run but returned no id');
        }

        return $runId;
    }

    /**
     * Stop a run.
     *
     * The container gets SIGTERM and the runner turns that into a cancellation:
     * it aborts the agent and still tries to deliver a `cancelled` artifact.
     *
     * @throws AyosException
     */
    public function stop(string $runId): void
    {
        try {
            $this->send('post', sprintf('job-runs/%s/stop', $runId));
        } catch (AyosException $exception) {
            // A run that is already gone cannot be stopped, and does not need
            // to be: cancellation has effectively succeeded.
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * What Scaleway believes about a run.
     *
     * @throws AyosException
     */
    public function status(string $runId): ?RunStatus
    {
        try {
            $run = $this->send('get', sprintf('job-runs/%s', $runId));
        } catch (AyosException $exception) {
            /*
             * A 404 is an answer: Scaleway has never heard of this run, or has
             * forgotten it. Either way nothing more is coming from it.
             */
            if ($exception->statusCode() === 404) {
                return RunStatus::Finished;
            }

            throw $exception;
        }

        $state = is_string($run['state'] ?? null) ? strtolower($run['state']) : '';

        return self::STATES[$state] ?? RunStatus::Running;
    }

    /**
     * Call the Serverless Jobs API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AyosException
     */
    protected function send(string $method, string $path, array $payload = []): array
    {
        $url = sprintf(
            '%s/serverless-jobs/%s/regions/%s/%s',
            rtrim((string) config('autofix.runner.scaleway.api_url'), '/'),
            self::API_VERSION,
            (string) config('autofix.runner.scaleway.region'),
            $path,
        );

        try {
            $response = $this->request()->{$method}($url, $payload);
        } catch (ConnectionException $exception) {
            throw AyosException::fromConnectionException($exception, $path);
        }

        if ($response->failed()) {
            throw AyosException::fromResponse($response, $path);
        }

        return (array) $response->json();
    }

    /**
     * The pending request every Scaleway call is built on.
     *
     * @throws AyosException
     */
    protected function request(): PendingRequest
    {
        $secret = config('autofix.runner.scaleway.secret_key');

        if (! is_string($secret) || $secret === '') {
            throw AyosException::missingConfiguration('autofix.runner.scaleway.secret_key');
        }

        return Http::acceptJson()
            ->withHeaders(['X-Auth-Token' => $secret])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * The job definition runs are started from.
     *
     * @throws AyosException
     */
    protected function definitionId(): string
    {
        $id = config('autofix.runner.scaleway.job_definition_id');

        if (! is_string($id) || $id === '') {
            throw AyosException::missingConfiguration('autofix.runner.scaleway.job_definition_id');
        }

        return $id;
    }
}
