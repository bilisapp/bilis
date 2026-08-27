<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Http\Middleware\VerifyAyosSignature;
use App\Models\FixJob;
use App\Models\ProjectRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The control-plane client for Ayos.
 *
 * Everything Ayos needs arrives in the job spec, minted per job and scoped as
 * narrowly as it goes: a `contents: read` installation token it can clone with
 * and nothing else, an LLM key, and the constraints it must respect. It never
 * receives the App private key, a write token, or any user or team identity.
 *
 * Both directions of the control plane are authenticated with one shared
 * secret — the same HMAC scheme `VerifyAyosSignature` checks on the way back.
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

    /**
     * How long an Ayos control-plane call may take.
     */
    public const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly GitHubAppTokenService $tokens,
        private readonly TaskRenderer $taskRenderer,
    ) {}

    /**
     * Hand one fix job to Ayos.
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
        $token = $this->tokens->installationToken(
            $repository->installation,
            $repository->repo_full_name,
            self::CLONE_PERMISSIONS,
        );

        $baseSha = $this->resolveBaseSha($repository, $token);

        $job->forceFill(['base_sha' => $baseSha])->save();

        $this->send('/jobs', $this->jobSpec($job, $repository, $token, $baseSha));

        $job->forceFill([
            'status' => FixJobStatus::Dispatched,
            'dispatched_at' => now(),
        ])->save();
    }

    /**
     * Ask Ayos to abort a running job.
     *
     * Ayos still posts a (failed) artifact to the callback, so the job's own
     * terminal state is set by the callback rather than here.
     *
     * @throws AyosException
     */
    public function cancel(FixJob $job): void
    {
        try {
            $this->send(sprintf('/jobs/%s/cancel', $job->uuid), ['job_id' => $job->uuid]);
        } catch (AyosException $exception) {
            /*
             * A 404 means Ayos no longer knows the job — its in-process state
             * was lost (restart) or already disposed. Nothing is left to
             * abort, so cancellation has effectively succeeded.
             */
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }
        }
    }

    /**
     * Build the job spec exactly as specs/ayos.md describes it.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function jobSpec(FixJob $job, ProjectRepository $repository, string $cloneToken, string $baseSha, array $extra = []): array
    {
        return [
            'job_id' => $job->uuid,
            'repo' => $repository->repo_full_name,
            'base_ref' => $repository->default_branch,
            'base_sha' => $baseSha,
            'clone_token' => $cloneToken,
            'llm_key' => $this->llmKey(),
            'task' => $this->taskRenderer->render($job),
            'constraints' => [
                'timeout_s' => (int) config('autofix.defaults.timeout_s', 900),
                'test_cmd' => $repository->test_cmd,
                'max_diff_lines' => (int) config('autofix.defaults.max_diff_lines', 800),
                'path_denylist' => $this->pathDenylist(),
            ],
            'callback_url' => route('api.internal.autofix.artifacts'),
            ...$extra,
        ];
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
     * POST a signed request to Ayos.
     *
     * The signature covers the raw body — the same scheme, byte for byte, that
     * `VerifyAyosSignature` checks on Ayos's callbacks — so the body is encoded
     * once here and sent verbatim: re-encoding it inside the HTTP client would
     * sign one string and transmit another. The timestamp travels alongside it
     * so the receiver can bound the replay window.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AyosException
     */
    protected function send(string $path, array $payload): Response
    {
        $url = $this->baseUrl().$path;
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = $this->request()
                ->withHeaders([
                    VerifyAyosSignature::TIMESTAMP_HEADER => $timestamp,
                    VerifyAyosSignature::SIGNATURE_HEADER => VerifyAyosSignature::signature($body, $this->sharedSecret()),
                    'Content-Type' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw AyosException::fromConnectionException($exception, $path);
        }

        if ($response->failed()) {
            throw AyosException::fromResponse($response, $path);
        }

        return $response;
    }

    /**
     * The pending request every Ayos call is built on.
     */
    protected function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * The configured Ayos base URL, without a trailing slash.
     *
     * @throws AyosException
     */
    protected function baseUrl(): string
    {
        $url = config('autofix.ayos.url');

        if (! is_string($url) || trim($url) === '') {
            throw AyosException::missingConfiguration('autofix.ayos.url');
        }

        return rtrim(trim($url), '/');
    }

    /**
     * The shared secret both directions of the control plane are signed with.
     *
     * @throws AyosException
     */
    protected function sharedSecret(): string
    {
        $secret = config('autofix.ayos.shared_secret');

        if (! is_string($secret) || $secret === '') {
            throw AyosException::missingConfiguration('autofix.ayos.shared_secret');
        }

        return $secret;
    }

    /**
     * The LLM credential forwarded for this job.
     *
     * @throws AyosException
     */
    protected function llmKey(): string
    {
        $key = config('autofix.llm.api_key');

        if (! is_string($key) || $key === '') {
            throw AyosException::missingConfiguration('autofix.llm.api_key');
        }

        return $key;
    }

    /**
     * The paths the agent is told never to touch.
     *
     * Ayos passes this to the agent; the diff validator re-enforces it on the
     * way back, because a prompt is not an access control.
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
