<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\SaveProjectRequest;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List the current team's projects.
     */
    public function index(Request $request): Response
    {
        $team = $this->team($request);

        return Inertia::render('projects/Index', [
            'projects' => $team->projects()
                ->withCount('apiKeys')
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'apiKeysCount' => (int) $project->getAttribute('api_keys_count'),
                    'createdAt' => $project->created_at?->toISOString(),
                ])
                ->values(),
        ]);
    }

    /**
     * Create a project for the current team.
     */
    public function store(SaveProjectRequest $request): RedirectResponse
    {
        $team = $this->team($request);

        $project = $team->projects()->create([
            'name' => $request->validated('name'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug]);
    }

    /**
     * Show a project and its API keys.
     */
    public function show(string $current_team, Project $project): Response
    {
        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'createdAt' => $project->created_at?->toISOString(),
            ],
            'apiKeys' => $project->apiKeys()
                ->latest('id')
                ->get()
                ->map(fn ($apiKey): array => [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'keyPrefix' => $apiKey->key_prefix,
                    'lastUsedAt' => $apiKey->last_used_at?->toISOString(),
                    'lastUsedForHumans' => $apiKey->last_used_at?->diffForHumans(),
                    'createdAt' => $apiKey->created_at?->toISOString(),
                ])
                ->values(),
            'teamSlug' => $current_team,
            'repository' => $this->repository($project),
            'installations' => $project->team->githubInstallations()
                ->orderBy('account_login')
                ->get()
                ->map(fn (GitHubInstallation $installation): array => [
                    'id' => $installation->installation_id,
                    'accountLogin' => $installation->account_login,
                    'accountType' => $installation->account_type,
                ])
                ->values(),
            'autofix' => [
                'enabled' => (bool) config('autofix.enabled'),
                'githubConfigured' => is_string(config('autofix.github.slug'))
                    && trim((string) config('autofix.github.slug')) !== '',
            ],
        ]);
    }

    /**
     * The project's connected repository, as the settings card renders it.
     *
     * @return array<string, mixed>|null
     */
    private function repository(Project $project): ?array
    {
        $repository = $project->repository()->with('installation')->first();

        if ($repository === null) {
            return null;
        }

        return [
            'id' => $repository->id,
            'repoFullName' => $repository->repo_full_name,
            'defaultBranch' => $repository->default_branch,
            'autofixEnabled' => $repository->autofix_enabled,
            'testCmd' => $repository->test_cmd,
            'maxConcurrent' => $repository->max_concurrent,
            'dailyBudget' => $repository->daily_budget,
            'accountLogin' => $repository->installation->account_login,
        ];
    }

    /**
     * Rename a project.
     */
    public function update(SaveProjectRequest $request, string $current_team, Project $project): RedirectResponse
    {
        $project->update(['name' => $request->validated('name')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.show', ['current_team' => $current_team, 'project' => $project->slug]);
    }

    /**
     * Delete a project and every API key issued for it.
     */
    public function destroy(string $current_team, Project $project): RedirectResponse
    {
        $project->apiKeys()->delete();
        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index', ['current_team' => $current_team]);
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
