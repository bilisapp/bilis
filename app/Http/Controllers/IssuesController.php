<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Http\Controllers\Concerns\BuildsAutofixState;
use App\Http\Controllers\Concerns\ResolvesTeamProjects;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Services\Issues\ErrorFingerprinter;
use App\Services\Issues\IssueFilters;
use App\Services\Issues\IssueList;
use App\Services\Issues\IssueQuery;
use App\Services\Issues\IssueState;
use App\Services\Issues\IssueStorageUnavailable;
use App\Services\Logs\LogQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issues: exceptions grouped by their fingerprint, and what to do about them.
 *
 * The list nominates from ClickHouse's hourly table and merges each issue's
 * status from the app database ({@see IssueList}); the issue page is a point
 * lookup on `issue_summary` plus one event read from `otel_logs`, with the
 * charts, tag breakdown and recent events deferred.
 *
 * The project comes from the team-scoped `{project}` binding (list: from the
 * team's own projects), never from the fingerprint — a fingerprint is a hash
 * of a stack and the same one can exist in two projects.
 */
class IssuesController extends Controller
{
    use BuildsAutofixState;
    use ResolvesTeamProjects;

    /**
     * How long event rows live in otel_logs; an issue page never reads further back.
     */
    private const EVENT_RETENTION_DAYS = 30;

    /**
     * Show the issue list for the current team.
     */
    public function index(Request $request, IssueList $list, IssueQuery $issues): Response
    {
        $team = $this->team($request);
        $projects = $this->projects($team);
        $filters = IssueFilters::fromRequest($request);

        /** @var Collection<int, Project> $scoped */
        $scoped = $filters->project === null
            ? $projects
            : $projects->where('slug', $filters->project)->values();

        return Inertia::render('issues/Index', [
            'projects' => $projects
                ->map(fn (Project $project): array => ['name' => $project->name, 'slug' => $project->slug])
                ->values(),
            'filters' => $filters->toArray(),
            'periods' => array_keys(IssueFilters::PERIODS),
            /*
             * Asked of every project and without the window, so "nothing
             * crashed this week" never reads as "error reporting is not set up".
             */
            'hasIssues' => $issues->hasAnyIssues($this->projectIds($projects, null)),
            'hasProjects' => $projects->isNotEmpty(),
            'result' => Inertia::defer(fn (): array => $list->build($scoped, $filters)),
        ]);
    }

    /**
     * Show one issue, one of its events, and everything known about both.
     */
    public function show(
        Request $request,
        string $currentTeam,
        Project $project,
        string $fingerprint,
        IssueQuery $issues,
        LogQuery $logs,
        ErrorFingerprinter $fingerprinter,
    ): Response {
        $team = $this->team($request);
        $navigation = $request->validate([
            'position' => ['nullable', 'string', 'in:latest,oldest,older,newer,at'],
            'event' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,9})?$/'],
        ]);

        $summary = $this->summaryOrFail($issues, $project, $fingerprint);
        $state = $this->state($project, $fingerprint);
        $projectId = (string) $project->getKey();
        [$from, $to] = $this->eventWindow($summary['firstSeen'], $summary['lastSeen']);

        $event = $logs->issueEvent(
            $projectId,
            $fingerprint,
            $from,
            $to,
            $navigation['position'] ?? 'latest',
            $navigation['event'] ?? null,
        );

        $row = $event['row'];
        $now = Carbon::now('UTC');

        return Inertia::render('issues/Show', [
            'issue' => [
                ...$summary,
                'projectSlug' => $project->slug,
                'projectName' => $project->name,
                'status' => IssueState::of($state, $summary['lastSeen']),
                'resolvedAt' => $state?->resolved_at?->toIso8601String(),
                'statusChangedBy' => $state?->statusChangedBy?->name,
                'statusChangedAt' => $state?->updated_at?->toIso8601String(),
            ],
            'event' => [
                'row' => $row,
                'hasOlder' => $event['hasOlder'],
                'hasNewer' => $event['hasNewer'],
                'unavailable' => $event['unavailable'],
                // A summary outlives its events (90 days against 30), so an
                // issue can be listed with nothing left to open.
                'expired' => $row === null && ! $event['unavailable'],
            ],
            'exception' => $row === null ? null : [
                'type' => $fingerprinter->exceptionClass($row),
                'message' => $fingerprinter->message($row),
                'stacktrace' => $fingerprinter->stackTrace($row),
            ],
            'autofix' => $this->autofixState($team, new Collection([$project])),
            'histogram' => Inertia::defer(fn (): array => [
                'day' => $issues->histogram($projectId, $fingerprint, $now->clone()->subDay(), $now),
                'month' => $issues->histogram($projectId, $fingerprint, $now->clone()->subDays(self::EVENT_RETENTION_DAYS), $now),
            ]),
            'tags' => Inertia::defer(fn (): array => $logs->issueTags($projectId, $fingerprint, $from, $to), 'details'),
            'recentEvents' => Inertia::defer(fn (): array => $logs->issueEvents($projectId, $fingerprint, $from, $to), 'details'),
        ]);
    }

    /**
     * Resolve, ignore or reopen an issue.
     *
     * The row is created on the first decision, so an issue nobody has
     * touched costs nothing in the app database.
     */
    public function update(UpdateIssueRequest $request, string $currentTeam, Project $project, string $fingerprint, IssueQuery $issues): RedirectResponse
    {
        $this->summaryOrFail($issues, $project, $fingerprint);

        $status = IssueStatus::from((string) $request->validated('status'));

        Issue::query()->updateOrCreate(
            ['project_id' => $project->getKey(), 'fingerprint' => $fingerprint],
            [
                'status' => $status,
                'resolved_at' => $status === IssueStatus::Resolved ? now() : null,
                'status_changed_by' => $request->user()?->getKey(),
            ],
        );

        return back();
    }

    /**
     * The issue's headline, or a 404 when this project has no such issue.
     *
     * @return array{projectId: string, fingerprint: string, type: string, message: string, origin: string, service: string, services: list<string>, environments: list<string>, firstSeen: int, lastSeen: int, events: int, users: int, firstRelease: string, lastRelease: string}
     */
    private function summaryOrFail(IssueQuery $issues, Project $project, string $fingerprint): array
    {
        try {
            $summary = $issues->summary((string) $project->getKey(), $fingerprint);
        } catch (IssueStorageUnavailable) {
            abort(503, 'Issue storage is busy. Try again in a moment.');
        }

        abort_if($summary === null, 404);

        return $summary;
    }

    private function state(Project $project, string $fingerprint): ?Issue
    {
        return Issue::query()
            ->with('statusChangedBy')
            ->where('project_id', $project->getKey())
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    /**
     * The window an issue's events can be read in: its own first and last
     * seen, a second either side, and never past the events' retention.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function eventWindow(int $firstSeenMs, int $lastSeenMs): array
    {
        $retention = Carbon::now('UTC')->subDays(self::EVENT_RETENTION_DAYS);
        $from = Carbon::createFromTimestampMsUTC($firstSeenMs)->subSecond();
        $to = Carbon::createFromTimestampMsUTC($lastSeenMs)->addSecond();

        return [$from->lessThan($retention) ? $retention : $from, $to];
    }
}
