<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsAutofixState;
use App\Models\Project;
use App\Models\Team;
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
    use BuildsAutofixState;

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

        $result = $logQuery->tail($projectIds, $filters, is_string($after) ? $this->tailCursor($after) : null);

        return response()->json($result);
    }

    /**
     * The tail's `after` bound, at the precision the row it came from had.
     *
     * The browser sends back the newest row's own Timestamp, which ClickHouse
     * rendered with nine fractional digits. Carbon keeps six, so a row stamped
     * `…00.123456789` came back as `> …00.123456` — which it is — and was sent
     * again on every poll. A naive UTC timestamp is passed through untouched;
     * anything else (an offset, the filter's own `to`) goes through Carbon.
     */
    private function tailCursor(string $after): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?)Z?$/', $after, $parts) === 1) {
            return $parts[1].' '.$parts[2];
        }

        return Carbon::parse($after)->utc()->format('Y-m-d H:i:s.u');
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
