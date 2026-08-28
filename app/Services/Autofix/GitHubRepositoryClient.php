<?php

namespace App\Services\Autofix;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The repository half of the GitHub REST API, over the `Http` facade.
 *
 * No git binary, no clone, no composer package: the write path reads the tree
 * at a commit, fetches the blobs it needs, and pushes a new commit back
 * through the Git Data API. Every method takes its token as an argument
 * because the caller — not this class — decides whether the operation is
 * allowed a write-scoped one.
 */
class GitHubRepositoryClient
{
    /**
     * How long a single API call may take.
     */
    public const TIMEOUT_SECONDS = 20;

    /**
     * Read the tree of a commit, recursively.
     *
     * One call hands back every path in the repository with its blob sha and
     * its file mode, which is what makes both "does this file exist" and
     * "keep it executable" answerable without a call per file.
     *
     * @return array{sha: string, truncated: bool, entries: array<string, array{sha: string, mode: string}>}
     *
     * @throws GitHubAppException
     */
    public function tree(string $token, string $repo, string $sha): array
    {
        $response = $this->get($token, sprintf('/repos/%s/git/trees/%s', $repo, rawurlencode($sha)), ['recursive' => '1'], 'the tree of '.$sha);

        $treeSha = $response->json('sha');
        $entries = $response->json('tree');

        if (! is_string($treeSha) || ! is_array($entries)) {
            throw GitHubAppException::fromUnexpectedPayload('the tree of '.$sha);
        }

        $paths = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'blob') {
                continue;
            }

            $path = $entry['path'] ?? null;
            $blob = $entry['sha'] ?? null;
            $mode = $entry['mode'] ?? null;

            if (! is_string($path) || ! is_string($blob)) {
                continue;
            }

            $paths[$path] = ['sha' => $blob, 'mode' => is_string($mode) ? $mode : PullRequestPublisher::DEFAULT_FILE_MODE];
        }

        return [
            'sha' => $treeSha,
            'truncated' => $response->json('truncated') === true,
            'entries' => $paths,
        ];
    }

    /**
     * Read one blob's decoded content.
     *
     * @throws GitHubAppException
     */
    public function blob(string $token, string $repo, string $sha): string
    {
        $response = $this->get($token, sprintf('/repos/%s/git/blobs/%s', $repo, rawurlencode($sha)), [], 'blob '.$sha);

        return $this->decode($response, 'blob '.$sha);
    }

    /**
     * Read a file's content at a commit, or null when it is not there.
     *
     * The fallback for a truncated tree listing, where a path's blob sha is
     * not known up front.
     *
     * @throws GitHubAppException
     */
    public function fileContent(string $token, string $repo, string $path, string $ref): ?string
    {
        $url = sprintf('/repos/%s/contents/%s', $repo, $this->encodePath($path));
        $response = $this->send($token, 'get', $url, ['ref' => $ref]);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw GitHubAppException::fromApiResponse($response, sprintf('the contents of %s', $path));
        }

        return $this->decode($response, sprintf('the contents of %s', $path));
    }

    /**
     * Resolve the head commit of a branch.
     *
     * @throws GitHubAppException
     */
    public function headSha(string $token, string $repo, string $branch): string
    {
        $response = $this->get($token, sprintf('/repos/%s/commits/%s', $repo, rawurlencode($branch)), [], 'the head of '.$branch);

        $sha = $response->json('sha');

        if (! is_string($sha) || $sha === '') {
            throw GitHubAppException::fromUnexpectedPayload('the head of '.$branch);
        }

        return $sha;
    }

    /**
     * Store a blob and get its sha back.
     *
     * @throws GitHubAppException
     */
    public function createBlob(string $token, string $repo, string $content): string
    {
        $response = $this->post($token, sprintf('/repos/%s/git/blobs', $repo), [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ], 'a blob');

        return $this->sha($response, 'a blob');
    }

    /**
     * Build a tree on top of an existing one.
     *
     * @param  list<array<string, mixed>>  $entries
     *
     * @throws GitHubAppException
     */
    public function createTree(string $token, string $repo, string $baseTree, array $entries): string
    {
        $response = $this->post($token, sprintf('/repos/%s/git/trees', $repo), [
            'base_tree' => $baseTree,
            'tree' => $entries,
        ], 'a tree');

        return $this->sha($response, 'a tree');
    }

    /**
     * Commit a tree on top of one parent.
     *
     * @throws GitHubAppException
     */
    public function createCommit(string $token, string $repo, string $message, string $tree, string $parent): string
    {
        $response = $this->post($token, sprintf('/repos/%s/git/commits', $repo), [
            'message' => $message,
            'tree' => $tree,
            'parents' => [$parent],
        ], 'a commit');

        return $this->sha($response, 'a commit');
    }

    /**
     * Point a branch at a commit, creating it or forcing it into place.
     *
     * A stale branch from an earlier attempt at the same fingerprint is
     * overwritten rather than worked around: the branch belongs to this job
     * and nothing but this job ever writes to it.
     *
     * @throws GitHubAppException
     */
    public function createOrUpdateBranch(string $token, string $repo, string $branch, string $sha): void
    {
        $response = $this->send($token, 'post', sprintf('/repos/%s/git/refs', $repo), [
            'ref' => 'refs/heads/'.$branch,
            'sha' => $sha,
        ]);

        if ($response->successful()) {
            return;
        }

        if ($response->status() !== 422) {
            throw GitHubAppException::fromApiResponse($response, sprintf('the branch %s', $branch));
        }

        $update = $this->send($token, 'patch', sprintf('/repos/%s/git/refs/heads/%s', $repo, $this->encodePath($branch)), [
            'sha' => $sha,
            'force' => true,
        ]);

        if ($update->failed()) {
            throw GitHubAppException::fromApiResponse($update, sprintf('the branch %s', $branch));
        }
    }

    /**
     * Open a pull request.
     *
     * @return array{number: int, url: string}
     *
     * @throws GitHubAppException
     */
    public function createPullRequest(string $token, string $repo, string $head, string $base, string $title, string $body): array
    {
        $response = $this->post($token, sprintf('/repos/%s/pulls', $repo), [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
            'maintainer_can_modify' => true,
        ], 'a pull request');

        return $this->pullRequest($response->json(), 'a pull request');
    }

    /**
     * Find the open pull request already raised from a branch, if any.
     *
     * @return array{number: int, url: string}|null
     *
     * @throws GitHubAppException
     */
    public function openPullRequestForBranch(string $token, string $repo, string $branch): ?array
    {
        $owner = explode('/', trim($repo, '/'))[0];

        $response = $this->get($token, sprintf('/repos/%s/pulls', $repo), [
            'head' => $owner.':'.$branch,
            'state' => 'open',
        ], 'the pull requests for '.$branch);

        $pulls = $response->json();

        if (! is_array($pulls) || $pulls === []) {
            return null;
        }

        $first = reset($pulls);

        return is_array($first) ? $this->pullRequest($first, 'the pull requests for '.$branch) : null;
    }

    /**
     * Post one comment on a pull request's conversation.
     *
     * Pull requests are issues as far as this endpoint is concerned, so the
     * comment goes to `/issues/{number}/comments` — the one write the
     * verification loop makes, and the reason it is handed a token scoped to
     * `pull_requests: write` and nothing else.
     *
     * @throws GitHubAppException
     */
    public function createIssueComment(string $token, string $repo, int $number, string $body): void
    {
        $this->post($token, sprintf('/repos/%s/issues/%d/comments', $repo, $number), [
            'body' => $body,
        ], sprintf('a comment on #%d', $number));
    }

    /**
     * Read a pull request's number and URL off a payload.
     *
     * @param  mixed  $payload
     * @return array{number: int, url: string}
     *
     * @throws GitHubAppException
     */
    protected function pullRequest($payload, string $operation): array
    {
        if (! is_array($payload)) {
            throw GitHubAppException::fromUnexpectedPayload($operation);
        }

        $number = $payload['number'] ?? null;
        $url = $payload['html_url'] ?? null;

        if (! is_int($number) || ! is_string($url)) {
            throw GitHubAppException::fromUnexpectedPayload($operation);
        }

        return ['number' => $number, 'url' => $url];
    }

    /**
     * Read the `sha` off a create response.
     *
     * @throws GitHubAppException
     */
    protected function sha(Response $response, string $operation): string
    {
        $sha = $response->json('sha');

        if (! is_string($sha) || $sha === '') {
            throw GitHubAppException::fromUnexpectedPayload($operation);
        }

        return $sha;
    }

    /**
     * Decode a base64 payload GitHub answered with.
     *
     * @throws GitHubAppException
     */
    protected function decode(Response $response, string $operation): string
    {
        $content = $response->json('content');
        $encoding = $response->json('encoding');

        if (! is_string($content)) {
            throw GitHubAppException::fromUnexpectedPayload($operation);
        }

        if ($encoding !== 'base64') {
            return $content;
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);

        if ($decoded === false) {
            throw GitHubAppException::fromUnexpectedPayload($operation);
        }

        return $decoded;
    }

    /**
     * Issue a GET that must succeed.
     *
     * @param  array<string, string>  $query
     *
     * @throws GitHubAppException
     */
    protected function get(string $token, string $path, array $query, string $operation): Response
    {
        $response = $this->send($token, 'get', $path, $query);

        if ($response->failed()) {
            throw GitHubAppException::fromApiResponse($response, $operation);
        }

        return $response;
    }

    /**
     * Issue a POST that must succeed.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws GitHubAppException
     */
    protected function post(string $token, string $path, array $payload, string $operation): Response
    {
        $response = $this->send($token, 'post', $path, $payload);

        if ($response->failed()) {
            throw GitHubAppException::fromApiResponse($response, $operation);
        }

        return $response;
    }

    /**
     * Issue one API call.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws GitHubAppException
     */
    protected function send(string $token, string $method, string $path, array $payload): Response
    {
        $request = $this->request($token);
        $url = GitHubAppTokenService::API_URL.$path;

        try {
            return match ($method) {
                'get' => $request->get($url, $payload),
                'patch' => $request->patch($url, $payload),
                default => $request->post($url, $payload),
            };
        } catch (ConnectionException $exception) {
            throw GitHubAppException::fromApiConnectionException($exception, $path);
        }
    }

    /**
     * The pending request every call is built on.
     */
    protected function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => GitHubAppTokenService::API_VERSION,
            ])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Encode a path for a URL without swallowing its separators.
     */
    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
