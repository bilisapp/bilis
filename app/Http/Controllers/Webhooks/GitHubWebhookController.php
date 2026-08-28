<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\FixJobStatus;
use App\Http\Controllers\Controller;
use App\Models\FixJob;
use App\Models\GitHubInstallation;
use App\Models\ProjectRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives GitHub App webhooks.
 *
 * The webhook is the source of truth for what the App is installed on: the
 * setup callback a user lands on after installing is UX sugar, and everything
 * it records is reconciled from here.
 *
 * One rule shapes the whole class: an installation this application has never
 * heard of belongs to no team, and a team is not something a webhook payload
 * gets to assert. Unknown installations are ignored rather than guessed at.
 */
class GitHubWebhookController extends Controller
{
    /**
     * Dispatch one delivery on its event name.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = (string) $request->header('X-GitHub-Event', '');
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';

        $handled = match ($event) {
            'installation' => $this->installation($action, $payload),
            'installation_repositories' => $this->installationRepositories($action, $payload),
            'pull_request' => $this->pullRequest($action, $payload),
            default => false,
        };

        if (! $handled) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse(['handled' => true]);
    }

    /**
     * An App installation appearing or going away.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function installation(string $action, array $payload): bool
    {
        $installationId = $this->installationId($payload);

        if ($installationId === null) {
            return false;
        }

        $installation = GitHubInstallation::query()->where('installation_id', $installationId)->first();

        /*
         * `created` reaches us before the setup callback has linked the
         * installation to a team as often as it reaches us after, so it only
         * ever refreshes a row that already exists. Creating one here would
         * mean inventing the team it belongs to.
         */
        if ($action === 'created') {
            if ($installation === null) {
                return false;
            }

            $account = $this->account($payload);

            $installation->forceFill(array_filter([
                'account_login' => $account['login'],
                'account_type' => $account['type'],
            ], fn (?string $value): bool => $value !== null && $value !== ''))->save();

            return true;
        }

        if ($action !== 'deleted') {
            return false;
        }

        if ($installation === null) {
            return false;
        }

        /*
         * The App is gone, so nothing may be fixed through it any more. The
         * opt-in is cleared first — anything reading a repository mid-request
         * sees it disabled — and then the installation row goes, which the
         * schema cascades down through `project_repositories` and the fix jobs
         * that hang off them. Losing that history is the price of the App
         * being uninstalled; nothing is left pointing at credentials that no
         * longer exist.
         */
        DB::transaction(function () use ($installation): void {
            ProjectRepository::query()
                ->where('github_installation_id', $installation->id)
                ->update(['autofix_enabled' => false]);

            $installation->delete();
        });

        return true;
    }

    /**
     * Repositories being added to or removed from an installation.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function installationRepositories(string $action, array $payload): bool
    {
        if ($action !== 'removed') {
            return false;
        }

        $installationId = $this->installationId($payload);

        if ($installationId === null) {
            return false;
        }

        $installation = GitHubInstallation::query()->where('installation_id', $installationId)->first();

        if ($installation === null) {
            return false;
        }

        $names = [];

        $removed = $payload['repositories_removed'] ?? null;

        if (is_array($removed)) {
            foreach ($removed as $repository) {
                $name = is_array($repository) ? ($repository['full_name'] ?? null) : null;

                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        if ($names === []) {
            return false;
        }

        /*
         * The row is kept and only disabled: access is gone, but the fix jobs
         * that ran against the repository are history worth keeping, and
         * re-adding the repository should not mean losing it.
         */
        ProjectRepository::query()
            ->where('github_installation_id', $installation->id)
            ->whereIn('repo_full_name', $names)
            ->update(['autofix_enabled' => false]);

        return true;
    }

    /**
     * A pull request closing, merged or not.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function pullRequest(string $action, array $payload): bool
    {
        if ($action !== 'closed') {
            return false;
        }

        $pull = $payload['pull_request'] ?? null;
        $repository = $payload['repository'] ?? null;

        if (! is_array($pull) || ! is_array($repository)) {
            return false;
        }

        $number = $pull['number'] ?? null;
        $fullName = $repository['full_name'] ?? null;

        if (! is_int($number) || ! is_string($fullName) || $fullName === '') {
            return false;
        }

        $job = FixJob::query()
            ->where('pr_number', $number)
            ->where('status', FixJobStatus::PrOpened)
            ->whereHas('repository', fn ($query) => $query->where('repo_full_name', $fullName))
            ->first();

        /*
         * Most closed pull requests in a connected repository were written by
         * people. A number that matches no open fix job is simply not ours.
         */
        if ($job === null) {
            return false;
        }

        $merged = ($pull['merged'] ?? null) === true;

        $job->forceFill([
            'status' => $merged ? FixJobStatus::Merged : FixJobStatus::Rejected,
            'failure_reason' => $merged ? null : 'pr_closed_unmerged',
            'completed_at' => now(),
        ])->save();

        return true;
    }

    /**
     * The GitHub installation id a delivery is about.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function installationId(array $payload): ?int
    {
        $installation = $payload['installation'] ?? null;
        $id = is_array($installation) ? ($installation['id'] ?? null) : null;

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The account an installation sits on.
     *
     * @param  array<string, mixed>  $payload
     * @return array{login: string|null, type: string|null}
     */
    protected function account(array $payload): array
    {
        $installation = $payload['installation'] ?? null;
        $account = is_array($installation) ? ($installation['account'] ?? null) : null;

        $login = is_array($account) ? ($account['login'] ?? null) : null;
        $type = is_array($account) ? ($account['type'] ?? null) : null;

        return [
            'login' => is_string($login) ? $login : null,
            'type' => is_string($type) ? $type : null,
        ];
    }
}
