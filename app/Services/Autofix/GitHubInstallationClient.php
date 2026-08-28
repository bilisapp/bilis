<?php

namespace App\Services\Autofix;

use App\Models\GitHubInstallation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The read-only half of the GitHub App, used by the settings UI.
 *
 * Two questions only, both answered before any repository is connected: who
 * did the App get installed on, and which repositories did they grant it. The
 * write path lives in `PullRequestPublisher` and shares nothing with this.
 *
 * The token minted here is deliberately *not* the one `GitHubAppTokenService`
 * hands out: that one is pinned to a single repository, which is exactly what
 * a "list the repositories" call cannot be. It is metadata-scoped, never
 * cached, and never leaves this class.
 */
class GitHubInstallationClient
{
    /**
     * The permissions the repository listing token is scoped to.
     *
     * @var array<string, string>
     */
    public const LISTING_PERMISSIONS = ['metadata' => 'read'];

    /**
     * How many repositories one listing returns at most.
     */
    public const PER_PAGE = 100;

    public function __construct(private readonly GitHubAppTokenService $tokens) {}

    /**
     * Look up the account an installation sits on.
     *
     * Answered with the App JWT rather than an installation token, because the
     * setup callback runs before any row exists to mint one against.
     *
     * @return array{login: string, type: string}
     *
     * @throws GitHubAppException
     */
    public function account(int $installationId): array
    {
        $response = $this->send(
            $this->request()->withToken($this->tokens->appJwt()),
            sprintf('%s/app/installations/%d', GitHubAppTokenService::API_URL, $installationId),
            $installationId,
        );

        $account = $response['account'] ?? null;
        $login = is_array($account) ? ($account['login'] ?? null) : null;
        $type = is_array($account) ? ($account['type'] ?? null) : null;

        if (! is_string($login) || $login === '') {
            throw GitHubAppException::fromInvalidResponse($installationId);
        }

        return [
            'login' => $login,
            'type' => is_string($type) && $type !== '' ? $type : 'User',
        ];
    }

    /**
     * List the repositories an installation has been granted.
     *
     * @return list<array{full_name: string, default_branch: string, private: bool}>
     *
     * @throws GitHubAppException
     */
    public function repositories(GitHubInstallation $installation): array
    {
        $payload = $this->send(
            $this->request()->withToken($this->listingToken($installation)),
            sprintf('%s/installation/repositories?per_page=%d', GitHubAppTokenService::API_URL, self::PER_PAGE),
            $installation->installation_id,
        );

        $repositories = $payload['repositories'] ?? [];

        if (! is_array($repositories)) {
            throw GitHubAppException::fromInvalidResponse($installation->installation_id);
        }

        $mapped = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository)) {
                continue;
            }

            $fullName = $repository['full_name'] ?? null;

            if (! is_string($fullName) || $fullName === '') {
                continue;
            }

            $branch = $repository['default_branch'] ?? null;

            $mapped[] = [
                'full_name' => $fullName,
                'default_branch' => is_string($branch) && $branch !== '' ? $branch : 'main',
                'private' => (bool) ($repository['private'] ?? false),
            ];
        }

        usort($mapped, fn (array $a, array $b): int => strcmp($a['full_name'], $b['full_name']));

        return $mapped;
    }

    /**
     * Mint an installation-wide, metadata-only token for the listing call.
     *
     * @throws GitHubAppException
     */
    protected function listingToken(GitHubInstallation $installation): string
    {
        $url = sprintf(
            '%s/app/installations/%d/access_tokens',
            GitHubAppTokenService::API_URL,
            $installation->installation_id,
        );

        try {
            $response = $this->request()
                ->withToken($this->tokens->appJwt())
                ->post($url, ['permissions' => self::LISTING_PERMISSIONS]);
        } catch (ConnectionException $exception) {
            throw GitHubAppException::fromConnectionException($exception, $installation->installation_id);
        }

        if ($response->failed()) {
            throw GitHubAppException::fromResponse($response, $installation->installation_id);
        }

        $token = $response->json('token');

        if (! is_string($token) || $token === '') {
            throw GitHubAppException::fromInvalidResponse($installation->installation_id);
        }

        return $token;
    }

    /**
     * GET a GitHub URL and decode it, or raise a domain exception.
     *
     * @return array<string, mixed>
     *
     * @throws GitHubAppException
     */
    protected function send(PendingRequest $request, string $url, int $installationId): array
    {
        try {
            $response = $request->get($url);
        } catch (ConnectionException $exception) {
            throw GitHubAppException::fromConnectionException($exception, $installationId);
        }

        if ($response->failed()) {
            throw GitHubAppException::fromResponse($response, $installationId);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw GitHubAppException::fromInvalidResponse($installationId);
        }

        return $payload;
    }

    /**
     * The pending request every call here is built on.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => GitHubAppTokenService::API_VERSION,
        ])->timeout(10);
    }
}
