<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogOnboarding;
use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    /**
     * Show the log viewer for the current team.
     */
    public function index(Request $request, LogQuery $logQuery, LogOnboarding $onboarding): Response
    {
        $team = $this->team($request);
        $filters = LogFilters::fromRequest($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        return Inertia::render('logs/Index', [
            'onboarding' => $onboarding->state($team, $this->projectIds($projects, null)),
            'projects' => $projects
                ->map(fn (Project $project): array => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                ])
                ->values(),
            'filters' => $filters->toArray(),
            'severityLevels' => SeverityLevel::values(),
            'services' => Inertia::defer(fn (): array => $logQuery->services($projectIds, $filters)),
            'logs' => Inertia::defer(fn (): array => $logQuery->search($projectIds, $filters)),
            'histogram' => Inertia::defer(
                fn (): array => $logQuery->histogram($projectIds, $filters),
                'histogram',
            ),
            /*
             * What a single log line can be handed to the agent for. Sent with
             * the page rather than asked for per row: it is two small queries
             * against the app database, and the row needs the answer the
             * moment a pointer touches it.
             */
            'autofix' => $this->autofixState($team, $projects),
        ]);
    }

    /**
     * Return the page of logs older than the cursor, for the "load older" button.
     *
     * The same query the page renders with, asked over XHR instead of an
     * Inertia visit: the viewer appends the rows to the ones it already shows,
     * so reading further back never costs the reader their scroll position.
     */
    public function older(Request $request, LogQuery $logQuery): JsonResponse
    {
        $team = $this->team($request);
        $filters = LogFilters::fromRequest($request);
        $projects = $this->projects($team);

        return response()->json(
            $logQuery->search($this->projectIds($projects, $filters->project), $filters),
        );
    }

    /**
     * Return the logs recorded after the given timestamp, for live tailing.
     */
    public function tail(Request $request, LogQuery $logQuery): JsonResponse
    {
        $team = $this->team($request);
        $filters = LogFilters::fromRequest($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        $after = $request->validate([
            'after' => ['nullable', 'date'],
        ])['after'] ?? null;

        $result = $logQuery->tail(
            $projectIds,
            $filters,
            is_string($after) ? Carbon::parse($after)->utc()->format('Y-m-d H:i:s.u') : null,
        );

        return response()->json($result);
    }

    /**
     * Which service of which project has a codebase behind it.
     *
     * The viewer shows lines from every project the team owns, and any one of
     * them may or may not have a repository responsible for its service. That
     * is a settings fact, not something a browser may assert, so the mapping
     * is resolved here and the row only reads it: the "Fix this" affordance is
     * offered exactly where the endpoint would accept it, and elsewhere the
     * same button explains what connecting a repository would buy.
     *
     * Every team project is listed, connected or not, because the offer made
     * to an unconnected one needs its slug to point at its settings page.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{enabled: bool, connected: bool, projects: array<string, array{slug: string, name: string, catchAll: string|null, services: array<string, string>}>, credentials: list<array<string, mixed>>}
     */
    private function autofixState(Team $team, Collection $projects): array
    {
        $enabled = config('autofix.enabled') === true;

        /** @var Collection<int, ProjectRepository> $repositories */
        $repositories = $enabled && $projects->isNotEmpty()
            ? ProjectRepository::query()
                ->whereIn('project_id', $projects->modelKeys())
                ->where('autofix_enabled', true)
                ->with('services')
                ->get()
            : new Collection;

        $map = [];

        foreach ($projects as $project) {
            $map[(string) $project->getKey()] = $this->autofixProjectState($project, $repositories);
        }

        return [
            'enabled' => $enabled,
            'connected' => $repositories->isNotEmpty(),
            'projects' => $map,
            /*
             * The keys the run dialog may choose between — never the keys
             * themselves, only the summary the settings page gets.
             */
            'credentials' => $enabled
                ? array_values($team->llmCredentials()
                    ->get()
                    ->map(fn (TeamLlmCredential $credential): array => $credential->toSummary())
                    ->all())
                : [],
        ];
    }

    /**
     * One project's service-to-repository claims, as the viewer reads them.
     *
     * @param  Collection<int, ProjectRepository>  $repositories
     * @return array{slug: string, name: string, catchAll: string|null, services: array<string, string>}
     */
    private function autofixProjectState(Project $project, Collection $repositories): array
    {
        $catchAll = null;
        $services = [];

        foreach ($repositories->where('project_id', $project->getKey()) as $repository) {
            foreach ($repository->services as $service) {
                if ($service->isCatchAll()) {
                    $catchAll = $repository->repo_full_name;

                    continue;
                }

                $services[$service->service_name] = $repository->repo_full_name;
            }
        }

        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'catchAll' => $catchAll,
            'services' => $services,
        ];
    }

    /**
     * The projects the current team owns, ordered by name.
     *
     * @return Collection<int, Project>
     */
    private function projects(Team $team): Collection
    {
        return $team->projects()->orderBy('name')->get();
    }

    /**
     * Resolve the project ids a query may read, always scoped to the team.
     *
     * ProjectId is a String column in ClickHouse, so the ids are cast here and
     * travel as strings from this point on.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<string>
     */
    private function projectIds(Collection $projects, ?string $slug): array
    {
        if ($slug !== null) {
            $projects = $projects->where('slug', $slug);
        }

        return array_values($projects->map(fn (Project $project): string => (string) $project->id)->all());
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
