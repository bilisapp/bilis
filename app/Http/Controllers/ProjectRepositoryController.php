<?php

namespace App\Http\Controllers;

use App\Http\Requests\Autofix\ConnectProjectRepositoryRequest;
use App\Http\Requests\Autofix\SaveProjectRepositoryRequest;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\ProjectRepositoryService;
use App\Models\Team;
use App\Services\Autofix\GitHubAppException;
use App\Services\Autofix\GitHubInstallationClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The repositories one project's autofix attempts are allowed to touch.
 *
 * Connecting one is the opt-in gate: until a repository exists and
 * `autofix_enabled` is on, nothing is ever dispatched.
 *
 * A project ships several services and they need not share a codebase, so it
 * may hold several repositories. What makes that answerable is the service
 * claim on each one (`project_repository_services`): given an error, exactly
 * one repository is responsible for fixing it. The first repository connected
 * takes the catch-all, so the ordinary one-repository project never has to
 * think about services at all.
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

        $this->connect($project, $installation, (string) $granted['full_name'], (string) $granted['default_branch']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository connected.')]);

        return back();
    }

    /**
     * Point the project at a repository without rewriting its history.
     *
     * The row is never repointed in place: jobs belong to a repository, so
     * mutating `repo_full_name` under them would silently re-file finished
     * work — and any in-flight job — against a codebase it never touched.
     * Connecting a different repository retires the old row (soft deleted, so
     * its jobs survive) and takes a fresh one; reconnecting a repository this
     * project had before restores that row and the jobs already under it.
     */
    private function connect(Project $project, GitHubInstallation $installation, string $repoFullName, string $defaultBranch): void
    {
        DB::transaction(function () use ($project, $installation, $repoFullName, $defaultBranch): void {
            $repository = ProjectRepository::withTrashed()->firstOrNew([
                'project_id' => $project->getKey(),
                'repo_full_name' => $repoFullName,
            ]);

            $repository->fill([
                'github_installation_id' => $installation->getKey(),
                'default_branch' => $defaultBranch,
            ]);

            $repository->deleted_at = null;
            $repository->save();

            /*
             * The first repository on a project takes every service. That is
             * what a one-repository project means, and it keeps the common
             * case free of configuration — only a second repository forces
             * anyone to say which service belongs to which codebase.
             */
            $claimed = ProjectRepositoryService::query()
                ->where('project_id', $project->getKey())
                ->exists();

            if (! $claimed) {
                $repository->services()->create([
                    'project_id' => $project->getKey(),
                    'service_name' => ProjectRepositoryService::CATCH_ALL,
                ]);
            }
        });

        $project->unsetRelation('repositories');
    }

    /**
     * Update one repository's autofix settings.
     */
    public function update(SaveProjectRepositoryRequest $request, string $current_team, Project $project, ProjectRepository $repository): RedirectResponse
    {
        abort_if($repository->project_id !== $project->getKey(), 404);

        DB::transaction(function () use ($request, $repository): void {
            $repository->update($request->settings());

            $this->claimServices($repository, $request->services());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Autofix settings saved.')]);

        return back();
    }

    /**
     * Disconnect the repository from the project.
     *
     * The fix jobs already raised against it stay: they are the record of what
     * was attempted, and losing them would lose the cooldown state with them.
     */
    public function destroy(string $current_team, Project $project, ProjectRepository $repository): RedirectResponse
    {
        abort_if($repository->project_id !== $project->getKey(), 404);

        DB::transaction(function () use ($repository): void {
            // Soft deleted: `fix_jobs` cascades from this row, and
            // disconnecting must not take the transcripts, pull requests and
            // fingerprint cooldowns of everything already attempted with it.
            $repository->update(['autofix_enabled' => false]);
            $repository->delete();

            /*
             * The claims go, though. They are settings rather than history —
             * and a soft-deleted row holding `checkout` would block another
             * repository from ever claiming it, with nothing on screen to
             * explain why.
             */
            $repository->services()->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository disconnected.')]);

        return back();
    }

    /**
     * Replace a repository's service claims with exactly this list.
     *
     * Written as a whole rather than diffed: the settings form sends the full
     * set, and a partial update would leave a claim behind that nobody can see
     * on the page they just saved.
     *
     * @param  list<string>  $services
     */
    private function claimServices(ProjectRepository $repository, array $services): void
    {
        $repository->services()->whereNotIn('service_name', $services)->delete();

        $existing = $repository->services()->pluck('service_name')->all();

        foreach (array_diff($services, $existing) as $service) {
            $repository->services()->create([
                'project_id' => $repository->project_id,
                'service_name' => $service,
            ]);
        }

        $repository->unsetRelation('services');
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
