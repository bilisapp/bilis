<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Services\Logs\LogFilters;
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
    public function index(Request $request, LogQuery $logQuery): Response
    {
        $team = $this->team($request);
        $filters = LogFilters::fromRequest($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        return Inertia::render('logs/Index', [
            'projects' => $projects
                ->map(fn (Project $project): array => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                ])
                ->values(),
            'filters' => $filters->toArray(),
            'severityLevels' => SeverityLevel::values(),
            'logs' => Inertia::defer(fn (): array => $logQuery->search($projectIds, $filters)),
        ]);
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
     * @param  Collection<int, Project>  $projects
     * @return list<int>
     */
    private function projectIds(Collection $projects, ?string $slug): array
    {
        if ($slug !== null) {
            $projects = $projects->where('slug', $slug);
        }

        return array_values($projects->map(fn (Project $project): int => $project->id)->all());
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
