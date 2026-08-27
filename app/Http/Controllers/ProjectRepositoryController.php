<?php

namespace App\Http\Controllers;

use App\Http\Requests\Autofix\ConnectProjectRepositoryRequest;
use App\Http\Requests\Autofix\SaveProjectRepositoryRequest;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Team;
use App\Services\Autofix\GitHubAppException;
use App\Services\Autofix\GitHubInstallationClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The repository one project's autofix attempts are allowed to touch.
 *
 * A project has at most one, and connecting it is the opt-in gate: until a
 * repository exists and `autofix_enabled` is on, nothing is ever dispatched.
 */
class ProjectRepositoryController extends Controller
{
    /**
     * List the repositories the team's installations have granted.
     *
     * Fetched over XHR rather than shipped in the page props: it is a live
     * GitHub call, and a project settings page must still render when GitHub
     * is having a bad day.
     */
    public function available(Request $request, GitHubInstallationClient $github, string $current_team, Project $project): JsonResponse
    {
        $installations = $this->installations($this->team($request));

        if ($installations->isEmpty()) {
            return response()->json(['installations' => [], 'unavailable' => false]);
        }

        $payload = [];

        foreach ($installations as $installation) {
            try {
                $repositories = $github->repositories($installation);
            } catch (GitHubAppException $exception) {
                report($exception);

                return response()->json(['installations' => [], 'unavailable' => true], 200);
            }

            $payload[] = [
                'id' => $installation->installation_id,
                'accountLogin' => $installation->account_login,
                'accountType' => $installation->account_type,
                'repositories' => $repositories,
            ];
        }

        return response()->json(['installations' => $payload, 'unavailable' => false]);
    }

    /**
     * Connect the project to one of the granted repositories.
     */
    public function store(ConnectProjectRepositoryRequest $request, GitHubInstallationClient $github, string $current_team, Project $project): RedirectResponse
    {
        $team = $this->team($request);
        $installation = $this->installations($team)
            ->firstWhere('installation_id', (int) $request->validated('installation_id'));

        abort_if($installation === null, 404);

        $repoFullName = (string) $request->validated('repo_full_name');

        try {
            $granted = collect($github->repositories($installation))->firstWhere('full_name', $repoFullName);
        } catch (GitHubAppException $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('GitHub could not be reached. Try again.')]);

            return back();
        }

        // The form named a repository; GitHub decides whether the App was ever
        // given it. Only the second answer counts.
        if ($granted === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('That repository is not shared with the Bilis GitHub App.'),
            ]);

            return back();
        }

        $project->repository()->updateOrCreate([], [
            'github_installation_id' => $installation->getKey(),
            'repo_full_name' => $granted['full_name'],
            'default_branch' => $granted['default_branch'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository connected.')]);

        return back();
    }

    /**
     * Update the repository's autofix settings.
     */
    public function update(SaveProjectRepositoryRequest $request, string $current_team, Project $project): RedirectResponse
    {
        $repository = $project->repository;

        abort_if($repository === null, 404);

        $repository->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Autofix settings saved.')]);

        return back();
    }

    /**
     * Disconnect the repository from the project.
     *
     * The fix jobs already raised against it stay: they are the record of what
     * was attempted, and losing them would lose the cooldown state with them.
     */
    public function destroy(string $current_team, Project $project): RedirectResponse
    {
        $repository = $project->repository;

        abort_if($repository === null, 404);

        $repository->update(['autofix_enabled' => false]);
        $repository->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository disconnected.')]);

        return back();
    }

    /**
     * The GitHub App installations the team owns.
     *
     * @return Collection<int, GitHubInstallation>
     */
    private function installations(Team $team): Collection
    {
        return GitHubInstallation::where('team_id', $team->getKey())->orderBy('account_login')->get();
    }

    /**
     * Resolve the team the request is scoped to.
     */
    private function team(Request $request): Team
    {
        $team = $request->route('current_team');

        if ($team instanceof Team) {
            return $team;
        }

        if (is_string($team)) {
            return Team::where('slug', $team)->firstOrFail();
        }

        abort(403);
    }
}
