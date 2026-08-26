<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\SaveProjectRequest;
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
        ]);
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
