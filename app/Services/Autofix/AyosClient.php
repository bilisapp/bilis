<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use App\Models\ProjectRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Starts and stops Ayos runs.
 *
 * Ayos used to be a service Bilis POSTed to. It is not one any more: a fix job
 * is one container run with no inbound HTTP, so there is nothing to call and
 * nothing to authenticate to. What is left is smaller and, in every direction
 * that matters, tighter:
 *
 * - **Per-run keys instead of a shared secret.** A keypair is minted here, the
 *   private half goes into the run and the public half onto the job row. There
 *   is no long-lived credential shared between the two services at all.
 * - **The clone token is single-use.** It now enters the same container as the
 *   agent, so Ayos revokes it the moment the clone finishes. That is why it is
 *   requested `fresh` below: a cached token would be handed to a second job
 *   after the first had already destroyed it.
 * - **The model credential belongs to the customer.** It is resolved from the
 *   job's own team rather than taken from config, so a leak from a run record
 *   costs one customer's budget instead of every customer's. Which provider it
 *   is for travels with it: the key and the host it is valid at are chosen by
 *   the party that holds the key, never guessed by the runner.
 *
 * Bilis still owns every credential: the GitHub App key, the LLM key and the
 * platform's own API key. Ayos receives short-lived, per-job material and
 * nothing else — no App private key, no write token, no user or team identity.
 */
class AyosClient
{
    /**
     * The permissions the token handed to Ayos is scoped to.
     *
     * Read only, one repository, and never `workflows`.
     *
     * @var array<string, string>
     */
    public const CLONE_PERMISSIONS = ['contents' => 'read'];

    public function __construct(
        private readonly GitHubAppTokenService $tokens,
        private readonly TaskRenderer $taskRenderer,
        private readonly RunDriver $runs,
        private readonly LlmCredentials $llm,
    ) {}

    /**
     * Hand one fix job to a fresh Ayos run.
     *
     * The commit the agent works from is pinned here rather than left to the
     * clone: Ayos checks out exactly the sha Bilis saw, so the diff that comes
     * back is against a known base and the validator can reason about it.
     *
     * @throws AyosException
     * @throws GitHubAppException
     */
    public function dispatch(FixJob $job): void
    {
        $repository = $job->repository;

        /*
         * Resolved first, and deliberately before any token is minted: a job
         * whose team has no usable key is going to fail either way, and it
         * should do so without having burned a single-use clone token on the
         * way.
         */
        $credential = $this->llm->forJob($job);

        /*
         * `fresh: true` is load-bearing. Read-only installation tokens are
         * cached for 50 minutes and shared between call sites, but Ayos revokes
         * this one as soon as it has cloned — so a cached token would send the
         * next job out with a credential the previous run already killed.
         */
        $token = $this->tokens->installationToken(
            $repository->installation,
            $repository->repo_full_name,
            self::CLONE_PERMISSIONS,
            fresh: true,
        );

        $baseSha = $this->resolveBaseSha($repository, $token);
        $keys = RunKeyPair::mint();

        /*
         * The public key lands on the row BEFORE the run starts. The run may
         * post its first event batch within a second of starting, and a
         * callback arriving before its own verification key is a 401 on a
         * perfectly good job.
         */
        $job->forceFill([
            'base_sha' => $baseSha,
            'ayos_public_key' => $keys->publicKey,
        ])->save();

        $spec = $this->jobSpec($job, $repository, $token, $baseSha, $keys, $credential);

        $runId = $this->runs->start($spec, $job->uuid);

        $credential->markUsed();

        $job->forceFill([
            'status' => FixJobStatus::Dispatched,
            'ayos_run_id' => $runId,
            'dispatched_at' => now(),
        ])->save();
    }

    /**
     * Stop a running job.
     *
     * The run is signalled rather than deleted: Ayos treats that as a
     * cancellation, aborts the agent and still tries to post a `cancelled`
     * artifact, so the job's terminal state is set by the callback rather than
     * guessed here.
     *
     * @throws AyosException
     */
    public function cancel(FixJob $job): void
    {
        if (! is_string($job->ayos_run_id) || $job->ayos_run_id === '') {
            // Never started, or started before this column existed. There is
            // nothing to stop, and saying so would be inventing a failure.
            return;
        }

        $this->runs->stop($job->ayos_run_id);
    }

    /**
     * What the platform believes about this job's run.
     *
     * `null` means the driver could not tell — which is emphatically not the
     * same as the run being dead, and must not be reconciled as one.
     *
     * @throws AyosException
     */
    public function runStatus(FixJob $job): ?RunStatus
    {
        if (! is_string($job->ayos_run_id) || $job->ayos_run_id === '') {
            return null;
        }

        return $this->runs->status($job->ayos_run_id);
    }

    /**
     * Build the job spec exactly as Ayos's SPEC.md describes it.
     *
     * @return string the JSON that becomes the run's `AYOS_JOB_SPEC`
     */
    protected function jobSpec(
        FixJob $job,
        ProjectRepository $repository,
        string $cloneToken,
        string $baseSha,
        RunKeyPair $keys,
        ResolvedLlmCredential $credential,
    ): string {
        return (string) json_encode([
            'job_id' => $job->uuid,
            'repo' => $repository->repo_full_name,
            'base_ref' => $repository->default_branch,
            'base_sha' => $baseSha,
            'clone_token' => $cloneToken,
            'llm_provider' => $credential->provider->value,
            'llm_key' => $credential->key,
            'llm_host' => $credential->host(),
            'signing_key' => $keys->signingKey,
            'task' => $this->taskRenderer->render($job),
            'constraints' => [
                'timeout_s' => (int) config('autofix.defaults.timeout_s', 900),
                'test_cmd' => $repository->test_cmd,
                'max_diff_lines' => (int) config('autofix.defaults.max_diff_lines', 800),
                'path_denylist' => $this->pathDenylist(),
            ],
            'callback_url' => route('api.internal.autofix.artifacts'),
            'events_url' => route('api.internal.autofix.events'),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve the head commit of the repository's default branch.
     *
     * @throws GitHubAppException
     */
    protected function resolveBaseSha(ProjectRepository $repository, string $token): string
    {
        $url = sprintf(
            '%s/repos/%s/commits/%s',
            GitHubAppTokenService::API_URL,
            trim($repository->repo_full_name, '/'),
            rawurlencode($repository->default_branch),
        );

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => GitHubAppTokenService::API_VERSION,
                ])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw GitHubAppException::fromConnectionException($exception, $repository->installation->installation_id);
        }

        if ($response->failed()) {
            throw GitHubAppException::fromResponse($response, $repository->installation->installation_id);
        }

        $sha = $response->json('sha');

        if (! is_string($sha) || $sha === '') {
            throw GitHubAppException::fromInvalidResponse($repository->installation->installation_id);
        }

        return $sha;
    }

    /**
     * The paths the agent is told never to touch.
     *
     * Ayos passes this to the agent and re-checks the packaged diff against it;
     * the diff validator checks again on the way back, because a prompt is not
     * an access control and neither is a check you did not run yourself.
     *
     * @return list<string>
     */
    protected function pathDenylist(): array
    {
        $denylist = config('autofix.defaults.path_denylist', []);

        if (! is_array($denylist)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $path): string => is_string($path) ? $path : '', $denylist),
            fn (string $path): bool => $path !== '',
        ));
    }
}
