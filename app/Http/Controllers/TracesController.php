<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Services\Traces\SpanTree;
use App\Services\Traces\TraceFilters;
use App\Services\Traces\TraceQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TracesController extends Controller
{
    /**
     * Show the trace list for the current team.
     */
    public function index(Request $request, TraceQuery $traces): Response
    {
        $team = $this->team($request);
        $filters = TraceFilters::fromRequest($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        return Inertia::render('traces/Index', [
            ...$this->shared($projects, $filters, $traces),
            'traces' => Inertia::defer(fn (): array => $traces->list($projectIds, $filters)),
        ]);
    }

    /**
     * Show per-service latency for the current team.
     *
     * A page of its own rather than a panel under the list. The two answer
     * different questions — "what happened to this request" against "which
     * service is slow" — and they were competing for the same screen: a chart
     * that grows a row per service pushed the traces themselves out of view.
     * They keep one toolbar and one query string, so switching tabs never
     * silently changes the window you are reading.
     */
    public function latency(Request $request, TraceQuery $traces): Response
    {
        $team = $this->team($request);
        $filters = TraceFilters::fromRequest($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        return Inertia::render('traces/Latency', [
            ...$this->shared($projects, $filters, $traces),
            'serviceLatency' => Inertia::defer(
                fn (): array => $traces->serviceLatency($projectIds, $filters),
                'latency',
            ),
        ]);
    }

    /**
     * The traces that started after the given time, for the list's live poll.
     *
     * Answered over XHR, not as an Inertia visit: the reader is looking at a
     * list and an Inertia visit would replace the page under them, taking their
     * scroll position and any open filter with it. The client merges the rows it
     * gets back by trace id, which is what lets the poll re-read the last few
     * seconds and let a still-arriving trace's span count settle.
     */
    public function tail(Request $request, TraceQuery $traces): JsonResponse
    {
        $team = $this->team($request);
        $filters = TraceFilters::fromRequest($request)->withoutCursor();
        $projectIds = $this->projectIds($this->projects($team), $filters->project);

        $after = $request->validate(['after' => ['nullable', 'date']])['after'] ?? null;

        return response()->json($traces->tail(
            $projectIds,
            $filters,
            // Timestamps come back from ClickHouse naive and in UTC, and that
            // is exactly what the client echoes here.
            is_string($after) ? Carbon::parse($after, 'UTC') : null,
        ));
    }

    /**
     * Show one trace's waterfall.
     *
     * The timestamp in the query string is the whole performance story here.
     * Almost every route to this page — a span, a log line, an error — already
     * knows when the trace happened, so it passes `?ts=`, and the span query is
     * bounded to seconds. Without it the summary table is asked first, which
     * costs one point lookup and is still far cheaper than scanning the
     * retention window.
     */
    public function show(Request $request, string $currentTeam, string $traceId, TraceQuery $traces): Response
    {
        $team = $this->team($request);
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, null);

        abort_unless(preg_match('/^[0-9a-fA-F]{32}$/', $traceId) === 1, 404);

        $traceId = strtolower($traceId);

        $validated = $request->validate(['ts' => ['nullable', 'date']]);

        $resolved = $this->resolve($traces, $projectIds, $traceId, $validated['ts'] ?? null);

        $spans = SpanTree::flatten($resolved['spans']['spans']);

        return Inertia::render('traces/Show', [
            'traceId' => $traceId,
            'summary' => $resolved['summary'],
            /*
             * The traces this one's spans point at with a link, and whether we
             * hold them. A link names a trace; naming one is not having it, and
             * a reader following a dead link deserves to be told that rather
             * than dropped on an empty waterfall.
             */
            'linkedTraces' => $traces->linkedTraces($projectIds, $this->linkedTraceIds($spans, $traceId)),
            /*
             * Read once from the root span rather than carried on every row: a
             * resource map is identical across a service's spans, so shipping it
             * per span would repeat the same object up to 2,000 times.
             */
            'resource' => $resolved['around'] === null
                ? null
                : $traces->rootResource($projectIds, $traceId, $resolved['around']),
            'spans' => $spans,
            'truncated' => $resolved['spans']['truncated'],
            'unavailable' => $resolved['spans']['unavailable'],
            'spanLimit' => TraceQuery::SPAN_LIMIT,
        ]);
    }

    /**
     * The same trace, for the preview panel the log viewer opens beside itself.
     *
     * Answered over XHR rather than as an Inertia visit on purpose: the reader
     * is still in the log stream and has not navigated anywhere. An Inertia
     * visit would swap the page, which is the exact thing the panel exists to
     * avoid.
     *
     * The payload is deliberately smaller than the page's — no resource map, no
     * span limit — because the panel is a peek, and "Go to detail" is one click
     * away for anything it does not carry.
     */
    public function panel(Request $request, string $currentTeam, string $traceId, TraceQuery $traces): JsonResponse
    {
        $team = $this->team($request);
        $projectIds = $this->projectIds($this->projects($team), null);

        abort_unless(preg_match('/^[0-9a-fA-F]{32}$/', $traceId) === 1, 404);

        $traceId = strtolower($traceId);

        $validated = $request->validate(['ts' => ['nullable', 'date']]);

        $resolved = $this->resolve($traces, $projectIds, $traceId, $validated['ts'] ?? null);

        return response()->json([
            'traceId' => $traceId,
            'summary' => $resolved['summary'],
            'spans' => SpanTree::flatten($resolved['spans']['spans']),
            'truncated' => $resolved['spans']['truncated'],
            'unavailable' => $resolved['spans']['unavailable'],
        ]);
    }

    /**
     * Resolve a trace's summary, its spans, and the window they were found in.
     *
     * Shared by the page and the panel, and shared deliberately: the `ts`
     * fallback below is subtle enough that two copies would diverge, and the
     * failure when they do is silent on both sides.
     *
     * @param  list<string>  $projectIds
     * @return array{summary: array<string, mixed>|null, around: Carbon|null, spans: array{spans: list<array<string, mixed>>, truncated: bool, unavailable: bool}}
     */
    private function resolve(TraceQuery $traces, array $projectIds, string $traceId, ?string $ts): array
    {
        $found = $traces->summary($projectIds, $traceId);
        $summary = $found['trace'];

        $around = $ts !== null
            ? Carbon::parse($ts)->utc()
            : ($summary === null ? null : Carbon::parse($summary['startedAt'], 'UTC'));

        /*
         * A summary with no resolvable time means nothing is stored for this id
         * at all. A summary that resolves but whose spans have aged out is a
         * different, designed state: the row is shown and the waterfall says so.
         */
        $spans = $around === null
            ? ['spans' => [], 'truncated' => false, 'unavailable' => false]
            : $traces->spans($projectIds, $traceId, $around);

        /*
         * `ts` is a hint, not a fact. It rides in from a link that may be old,
         * hand-edited, or written in the reader's own timezone, and a wrong one
         * lands the span query on an empty window — which is indistinguishable
         * from expired spans unless we look again. So when a supplied `ts` finds
         * nothing, fall back to the time the summary itself reports and retry
         * once. The optimisation stays (a good `ts` still costs one bounded
         * query), and the "spans have expired" message now only appears when the
         * trace's own recorded window is empty too, which is the one case where
         * it is true.
         */
        if ($spans['spans'] === [] && ! $spans['unavailable'] && $summary !== null && $ts !== null) {
            $recorded = Carbon::parse($summary['startedAt'], 'UTC');

            if (! $recorded->equalTo($around)) {
                $spans = $traces->spans($projectIds, $traceId, $recorded);
                // Everything downstream reads the window that actually found
                // spans, or the resource lookup repeats the same wrong query.
                $around = $recorded;
            }
        }

        /*
         * Either query being overloaded makes the whole answer provisional: a
         * missing summary because storage was busy must not be rendered as "no
         * such trace".
         */
        $spans['unavailable'] = $spans['unavailable'] || $found['unavailable'];

        return ['summary' => $summary, 'around' => $around, 'spans' => $spans];
    }

    /**
     * Every distinct trace a span in this set links to, except this one.
     *
     * A link back into the trace being read needs no lookup — the reader is
     * already there — and dropping it keeps the `IN` list to the traces the
     * page might actually have to offer a way out to.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<string>
     */
    private function linkedTraceIds(array $spans, string $traceId): array
    {
        $ids = [];

        foreach ($spans as $span) {
            /** @var list<array<string, mixed>> $links */
            $links = is_array($span['links'] ?? null) ? $span['links'] : [];

            foreach ($links as $link) {
                $linked = strtolower((string) ($link['traceId'] ?? ''));

                if ($linked !== '' && $linked !== $traceId) {
                    $ids[$linked] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * The props both trace tabs render with.
     *
     * The list and the latency chart wear the same toolbar and read the same
     * query string, so they must be handed the same projects, the same filters
     * and the same answer to "has this team ever sent a span" — built here once
     * rather than twice with a chance to drift.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function shared(Collection $projects, TraceFilters $filters, TraceQuery $traces): array
    {
        return [
            'projects' => $projects
                ->map(fn (Project $project): array => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                ])
                ->values(),
            'filters' => $filters->toArray(),
            /*
             * Whether this team has ever sent a span, asked without the filter
             * window: an empty hour must read as "nothing in this hour", not as
             * "traces are not set up".
             */
            'hasTraces' => $traces->hasAnyTraces($this->projectIds($projects, null)),
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
     * travel as strings from this point on. A slug never reaches SQL.
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
