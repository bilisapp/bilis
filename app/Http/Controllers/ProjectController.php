<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\SaveProjectRequest;
use App\Models\GitHubInstallation;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\ProjectRepositoryService;
use App\Models\Team;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * @param  LogQuery  $logs  reads the service names the claim editor suggests
     */
    public function __construct(private readonly LogQuery $logs) {}

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
            'repositories' => $this->repositories($project),
            /*
             * The service names actually seen in this project's logs, so the
             * claim editor offers real values rather than asking someone to
             * remember how their OTel resource is spelled. Best effort: a
             * ClickHouse that cannot answer costs autocomplete, not the page.
             */
            'observedServices' => $this->observedServices($project),
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
     * The service names this project has actually logged.
     *
     * Autocomplete for the claim editor, and nothing more: the claim itself is
     * free text, because a service that has not logged yet is exactly the one
     * you want to map before it starts failing.
     *
     * @return list<string>
     */
    private function observedServices(Project $project): array
    {
        return $this->logs->services(
            [(string) $project->getKey()],
            new LogFilters(from: Carbon::now()->subDays(7), to: Carbon::now()),
        );
    }

    /**
     * The project's connected repositories, as the settings cards render them.
     *
     * @return list<array<string, mixed>>
     */
    private function repositories(Project $project): array
    {
        /** @var list<array<string, mixed>> $repositories */
        $repositories = $project->repositories()
            ->with(['installation', 'services'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectRepository $repository): array => [
                'id' => $repository->id,
                'repoFullName' => $repository->repo_full_name,
                'defaultBranch' => $repository->default_branch,
                'autofixEnabled' => $repository->autofix_enabled,
                'testCmd' => $repository->test_cmd,
                'maxConcurrent' => $repository->max_concurrent,
                'dailyBudget' => $repository->daily_budget,
                'accountLogin' => $repository->installation->account_login,
                'services' => $repository->services
                    ->map(fn (ProjectRepositoryService $service): string => $service->service_name)
                    ->values()
                    ->all(),
                'isCatchAll' => $repository->isCatchAll(),
            ])
            ->values()
            ->all();

        return $repositories;
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
