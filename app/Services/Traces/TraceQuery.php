<?php

namespace App\Services\Traces;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Logs\LogQuery;
use Illuminate\Support\Carbon;

/**
 * Reads traces out of ClickHouse for the viewer.
 *
 * Two tables, two shapes. The trace list reads `trace_summary` only — one row
 * per trace per insert block, never the spans — so browsing stays cheap no
 * matter how wide the traces are. The waterfall reads `otel_traces`, always
 * inside a time window, because the sort key leads with `(ProjectId, Timestamp)`
 * and a bare TraceId lookup would otherwise scan the retention period.
 *
 * Project ids are always resolved server side and passed in as a list; a slug
 * never reaches SQL (SCHEMA.md R2, R3), and every user value is bound as a
 * `{name:Type}` parameter.
 */
class TraceQuery
{
    /**
     * The columns a waterfall needs from a span.
     */
    private const SPAN_COLUMNS = 'Timestamp, TraceId, SpanId, ParentSpanId, SpanName, SpanKind, ServiceName, Duration, StatusCode, StatusMessage, SpanAttributes, `Events.Timestamp`, `Events.Name`, `Events.Attributes`, `Links.TraceId`, `Links.SpanId`, `Links.TraceState`, `Links.Attributes`';

    /**
     * The most spans a single waterfall will read.
     *
     * A pathological trace can carry tens of thousands. The page renders the
     * tree from what came back and says how many spans the trace really has, so
     * the view degrades rather than hanging the browser and the server with it.
     */
    public const SPAN_LIMIT = 2000;

    /**
     * How far either side of the given timestamp a trace's spans are looked for.
     *
     * Asymmetric on purpose: the timestamp in the URL comes from a span, a log
     * line or an error that is usually *inside* the trace rather than at its
     * start, so the window has to reach further forward than back. A trace
     * longer than this loses its tail, which is why the count from
     * `trace_summary` is shown beside the tree.
     */
    private const SPAN_WINDOW_BEFORE_SECONDS = 60;

    private const SPAN_WINDOW_AFTER_SECONDS = 300;

    /**
     * How far back before the caller's cursor a tail poll re-reads.
     *
     * A trace is not finished when it first appears: its spans arrive over
     * hundreds of milliseconds and land in several insert blocks, so a row read
     * the instant the root span arrived carries a partial span count. Re-reading
     * the last few seconds on every poll lets those counts settle — the client
     * keys rows by trace id, so a re-read replaces rather than duplicates. The
     * cursor itself never moves backwards, so this window does not creep.
     */
    private const TAIL_OVERLAP_SECONDS = 10;

    /**
     * The statuses a span may carry, as the exporter spells them (R10).
     */
    private const STATUS_ERROR = 'Error';

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Fetch a page of traces, newest first.
     *
     * @param  list<string>  $projectIds
     * @return array{rows: list<array<string, mixed>>, nextCursor: string|null, unavailable: bool}
     */
    public function list(array $projectIds, TraceFilters $filters): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->summaryConditions($projectIds, $filters);

        /*
         * The re-aggregation is not decoration. trace_summary is an
         * AggregatingMergeTree, so one trace holds one row *per insert block*
         * until a merge collapses them: a trace whose spans arrived in three
         * batches is three rows, each with a partial SpanCount and only one of
         * them carrying the root name. Reading it without this GROUP BY shows
         * the trace three times with the wrong numbers, and does so only under
         * load — which is exactly when nobody is looking. See SCHEMA.md R11.
         */
        $having = $this->having($filters, $params);

        /*
         * The output aliases deliberately do NOT match the column names. In
         * ClickHouse an alias shadows the column it was built from, so
         * `sum(ErrorCount) AS ErrorCount` makes the `sum(ErrorCount)` in HAVING
         * resolve to `sum(sum(ErrorCount))` and the query dies with
         * ILLEGAL_AGGREGATION. Renaming the output is the fix; SCHEMA.md R11.
         */

        $sql = sprintf(
            'SELECT
                TraceId,
                max(RootName)    AS TraceRootName,
                max(RootService) AS TraceRootService,
                min(Start)       AS Started,
                max(End)         AS Ended,
                sum(SpanCount)   AS TraceSpanCount,
                sum(ErrorCount)  AS TraceErrorCount
             FROM trace_summary
             WHERE %s
             GROUP BY ProjectId, TraceId
             %s
             ORDER BY Started DESC
             LIMIT {rowLimit:UInt32}',
            implode(' AND ', $conditions),
            $having === [] ? '' : 'HAVING '.implode(' AND ', $having),
        );

        $params['rowLimit'] = $filters->limit;

        $rows = $this->select($sql, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        $traces = array_values(array_map($this->mapTrace(...), $rows));

        $nextCursor = count($traces) < $filters->limit
            ? null
            : ($traces[count($traces) - 1]['startedAt'] ?? null);

        return ['rows' => $traces, 'nextCursor' => $nextCursor, 'unavailable' => false];
    }

    /**
     * Fetch the traces that started after the given time, newest first.
     *
     * The trace list's twin, for polling. The window's upper bound is dropped
     * the way {@see LogQuery::tail()} drops it: a trace that
     * arrives after the page loaded is by definition past `to`, so keeping the
     * bound would guarantee an empty answer forever.
     *
     * Everything else the list does still applies — the GROUP BY of R11 above
     * all, since a trace this fresh is exactly the one whose rows have not been
     * merged yet.
     *
     * @param  list<string>  $projectIds
     * @return array{rows: list<array<string, mixed>>, nextCursor: string|null, unavailable: bool}
     */
    public function tail(array $projectIds, TraceFilters $filters, ?Carbon $after): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        $since = ($after ?? $filters->to)->clone()->utc()->subSeconds(self::TAIL_OVERLAP_SECONDS);

        $conditions = [
            'ProjectId IN {projectIds:Array(String)}',
            'Start > {after:DateTime64(9)}',
        ];

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'after' => $this->formatTimestamp($since),
        ];

        $having = $this->having($filters, $params);

        $sql = sprintf(
            'SELECT
                TraceId,
                max(RootName)    AS TraceRootName,
                max(RootService) AS TraceRootService,
                min(Start)       AS Started,
                max(End)         AS Ended,
                sum(SpanCount)   AS TraceSpanCount,
                sum(ErrorCount)  AS TraceErrorCount
             FROM trace_summary
             WHERE %s
             GROUP BY ProjectId, TraceId
             %s
             ORDER BY Started DESC
             LIMIT {rowLimit:UInt32}',
            implode(' AND ', $conditions),
            $having === [] ? '' : 'HAVING '.implode(' AND ', $having),
        );

        $params['rowLimit'] = $filters->limit;

        $rows = $this->select($sql, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        return [
            'rows' => array_values(array_map($this->mapTrace(...), $rows)),
            'nextCursor' => null,
            'unavailable' => false,
        ];
    }

    /**
     * Fetch one trace's spans, bounded to a window around the given time.
     *
     * @param  list<string>  $projectIds
     * @return array{spans: list<array<string, mixed>>, truncated: bool, unavailable: bool}
     */
    public function spans(array $projectIds, string $traceId, Carbon $around): array
    {
        if ($projectIds === []) {
            return ['spans' => [], 'truncated' => false, 'unavailable' => false];
        }

        $sql = sprintf(
            'SELECT %s FROM otel_traces
             WHERE ProjectId IN {projectIds:Array(String)}
               AND Timestamp >= {from:DateTime64(9)}
               AND Timestamp <= {to:DateTime64(9)}
               AND TraceId = {traceId:String}
             ORDER BY Timestamp ASC
             LIMIT {rowLimit:UInt32}',
            self::SPAN_COLUMNS,
        );

        $rows = $this->select($sql, [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($around->clone()->subSeconds(self::SPAN_WINDOW_BEFORE_SECONDS)),
            'to' => $this->formatTimestamp($around->clone()->addSeconds(self::SPAN_WINDOW_AFTER_SECONDS)),
            'traceId' => strtolower($traceId),
            // One more than the cap, so a full page is distinguishable from an
            // exactly-full trace without a second count query.
            'rowLimit' => self::SPAN_LIMIT + 1,
        ]);

        if ($rows === null) {
            return ['spans' => [], 'truncated' => false, 'unavailable' => true];
        }

        $truncated = count($rows) > self::SPAN_LIMIT;

        return [
            'spans' => array_map($this->mapSpan(...), array_slice($rows, 0, self::SPAN_LIMIT)),
            'truncated' => $truncated,
            'unavailable' => false,
        ];
    }

    /**
     * The summary of one trace, or null when nothing is stored for it.
     *
     * This is what makes a pasted trace id work. The summary table is keyed on
     * `(ProjectId, TraceId)`, so it answers "when did this trace happen?" as a
     * point lookup; the answer then bounds the span query to seconds instead of
     * the whole 30-day retention window.
     *
     * Returns an envelope rather than a bare row because "no such trace" and
     * "storage could not answer" are different answers that must not collapse
     * into the same `null`: one is a fact about the trace, the other a fact
     * about the server, and telling a reader their trace does not exist because
     * ClickHouse was busy is the worse of the two lies.
     *
     * @param  list<string>  $projectIds
     * @return array{trace: array<string, mixed>|null, unavailable: bool}
     */
    public function summary(array $projectIds, string $traceId): array
    {
        if ($projectIds === []) {
            return ['trace' => null, 'unavailable' => false];
        }

        $rows = $this->select(
            'SELECT
                TraceId,
                max(RootName)    AS TraceRootName,
                max(RootService) AS TraceRootService,
                min(Start)       AS Started,
                max(End)         AS Ended,
                sum(SpanCount)   AS TraceSpanCount,
                sum(ErrorCount)  AS TraceErrorCount
             FROM trace_summary
             WHERE ProjectId IN {projectIds:Array(String)} AND TraceId = {traceId:String}
             GROUP BY ProjectId, TraceId
             LIMIT 1',
            [
                'projectIds' => $this->stringArrayParameter($projectIds),
                'traceId' => strtolower($traceId),
            ],
        );

        if ($rows === null) {
            return ['trace' => null, 'unavailable' => true];
        }

        return [
            'trace' => $rows === [] ? null : $this->mapTrace($rows[0]),
            'unavailable' => false,
        ];
    }

    /**
     * Which of the given trace ids this instance actually holds.
     *
     * A span link names a trace, and naming one is not the same as having it: a
     * link routinely points at a trace produced by another process that never
     * shipped to this endpoint, or at one that has aged out. The UI has to tell
     * those apart from a trace it can open, and it cannot do that from the link
     * alone.
     *
     * Answered from `trace_summary`, whose key is `(ProjectId, TraceId)`, so it
     * is a point lookup per id rather than a scan — and it comes back with each
     * trace's start, which is what makes the outgoing link carry a `ts` and stay
     * bounded. Re-aggregated like every other read of that table (R11).
     *
     * @param  list<string>  $projectIds
     * @param  list<string>  $traceIds
     * @return array<string, array<string, mixed>>
     */
    public function linkedTraces(array $projectIds, array $traceIds): array
    {
        $traceIds = array_values(array_unique(array_filter(array_map(strtolower(...), $traceIds))));

        if ($projectIds === [] || $traceIds === []) {
            return [];
        }

        $rows = $this->select(
            'SELECT
                TraceId,
                max(RootName)    AS TraceRootName,
                max(RootService) AS TraceRootService,
                min(Start)       AS Started,
                max(End)         AS Ended,
                sum(SpanCount)   AS TraceSpanCount,
                sum(ErrorCount)  AS TraceErrorCount
             FROM trace_summary
             WHERE ProjectId IN {projectIds:Array(String)} AND TraceId IN {traceIds:Array(String)}
             GROUP BY ProjectId, TraceId
             LIMIT {rowLimit:UInt32}',
            [
                'projectIds' => $this->stringArrayParameter($projectIds),
                'traceIds' => $this->stringArrayParameter($traceIds),
                'rowLimit' => count($traceIds),
            ],
        );

        /*
         * An overloaded ClickHouse yields "none of them", which renders as links
         * that cannot be followed rather than as a page that failed. The link
         * target text says the trace is not stored *here*, which stays true
         * either way.
         */
        if ($rows === null) {
            return [];
        }

        $traces = [];

        foreach ($rows as $row) {
            $trace = $this->mapTrace($row);
            $traces[(string) $trace['traceId']] = $trace;
        }

        return $traces;
    }

    /**
     * The resource a trace was produced by, read from its root span.
     *
     * Everything the header says about the *trace* rather than about one span —
     * environment, service version, the SDK that emitted it — lives in resource
     * attributes, which are identical across a service's spans. Fetching them
     * once here keeps them off the 2,000-row waterfall payload, where the same
     * map would be repeated on every span.
     *
     * Prefers the root span but does not require one: a trace whose root has not
     * arrived still has a resource worth showing.
     *
     * @param  list<string>  $projectIds
     * @return array{attributes: array<string, string>, scopeName: string, scopeVersion: string}|null
     */
    public function rootResource(array $projectIds, string $traceId, Carbon $around): ?array
    {
        if ($projectIds === []) {
            return null;
        }

        $rows = $this->select(
            'SELECT ResourceAttributes, ScopeName, ScopeVersion FROM otel_traces
             WHERE ProjectId IN {projectIds:Array(String)}
               AND Timestamp >= {from:DateTime64(9)}
               AND Timestamp <= {to:DateTime64(9)}
               AND TraceId = {traceId:String}
             ORDER BY ParentSpanId = \'\' DESC
             LIMIT 1',
            [
                'projectIds' => $this->stringArrayParameter($projectIds),
                'from' => $this->formatTimestamp($around->clone()->subSeconds(self::SPAN_WINDOW_BEFORE_SECONDS)),
                'to' => $this->formatTimestamp($around->clone()->addSeconds(self::SPAN_WINDOW_AFTER_SECONDS)),
                'traceId' => strtolower($traceId),
            ],
        );

        if ($rows === null || $rows === []) {
            return null;
        }

        /** @var array<string, string> $attributes */
        $attributes = is_array($rows[0]['ResourceAttributes'] ?? null) ? $rows[0]['ResourceAttributes'] : [];

        return [
            'attributes' => $attributes,
            'scopeName' => (string) ($rows[0]['ScopeName'] ?? ''),
            'scopeVersion' => (string) ($rows[0]['ScopeVersion'] ?? ''),
        ];
    }

    /**
     * Latency and error rate per service over the filtered window.
     *
     * @param  list<string>  $projectIds
     * @return array{rows: list<array<string, mixed>>, unavailable: bool}
     */
    public function serviceLatency(array $projectIds, TraceFilters $filters): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'unavailable' => false];
        }

        $conditions = [
            'ProjectId IN {projectIds:Array(String)}',
            'Timestamp >= {from:DateTime64(9)}',
            'Timestamp <= {to:DateTime64(9)}',
        ];

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($filters->from),
            'to' => $this->formatTimestamp($filters->to),
        ];

        if ($filters->service !== null) {
            $conditions[] = 'ServiceName = {service:String}';
            $params['service'] = $filters->service;
        }

        $sql = sprintf(
            'SELECT
                ServiceName,
                count()                                AS Spans,
                quantile(0.95)(Duration)               AS P95,
                quantile(0.99)(Duration)               AS P99,
                countIf(StatusCode = {errorStatus:String}) AS Errors
             FROM otel_traces
             WHERE %s
             GROUP BY ServiceName
             ORDER BY Spans DESC
             LIMIT 20',
            implode(' AND ', $conditions),
        );

        $params['errorStatus'] = self::STATUS_ERROR;

        $rows = $this->select($sql, $params);

        if ($rows === null) {
            return ['rows' => [], 'unavailable' => true];
        }

        return [
            'rows' => array_values(array_map(function (array $row): array {
                $spans = (int) ($row['Spans'] ?? 0);
                $errors = (int) ($row['Errors'] ?? 0);

                return [
                    'serviceName' => (string) ($row['ServiceName'] ?? ''),
                    'spans' => $spans,
                    // Nanoseconds on the wire; the UI formats from milliseconds.
                    'p95Ms' => round(((float) ($row['P95'] ?? 0)) / 1_000_000, 2),
                    'p99Ms' => round(((float) ($row['P99'] ?? 0)) / 1_000_000, 2),
                    'errors' => $errors,
                    'errorRate' => $spans === 0 ? 0.0 : round($errors / $spans, 4),
                ];
            }, $rows)),
            'unavailable' => false,
        ];
    }

    /**
     * Whether this team has ever stored a span.
     *
     * Deliberately unconstrained in time and stopped at the first row, the same
     * shape as `LogQuery::hasAnyLogs()`: it answers "is this surface set up
     * yet?", which an empty filter window must not be able to answer wrongly.
     *
     * @param  list<string>  $projectIds
     */
    public function hasAnyTraces(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        $rows = $this->select(
            'SELECT 1 FROM trace_summary WHERE ProjectId IN {projectIds:Array(String)} LIMIT 1',
            ['projectIds' => $this->stringArrayParameter($projectIds)],
        );

        // An overloaded ClickHouse answers true: a hiccup must never make an
        // established team look brand new.
        return $rows === null || $rows !== [];
    }

    /**
     * The base predicate for the trace list.
     *
     * Assembled here and nowhere else: callers append to what comes back and
     * nothing may drop the ProjectId predicate it starts with (R4).
     *
     * @param  list<string>  $projectIds
     * @return array{0: list<string>, 1: array<string, scalar|null>}
     */
    private function summaryConditions(array $projectIds, TraceFilters $filters): array
    {
        return [
            [
                'ProjectId IN {projectIds:Array(String)}',
                'Start >= {from:DateTime64(9)}',
                'Start <= {to:DateTime64(9)}',
            ],
            [
                'projectIds' => $this->stringArrayParameter($projectIds),
                'from' => $this->formatTimestamp($filters->from),
                'to' => $this->formatTimestamp($filters->to),
            ],
        ];
    }

    /**
     * The filters that can only be applied after the rows are re-aggregated.
     *
     * Error count, duration and the cursor are all sums or extremes across a
     * trace's rows, so none of them mean anything until the GROUP BY has run.
     *
     * @param  array<string, scalar|null>  $params
     * @return list<string>
     */
    private function having(TraceFilters $filters, array &$params): array
    {
        $having = [];

        if ($filters->errorsOnly) {
            $having[] = 'sum(ErrorCount) > 0';
        }

        if ($filters->minDurationMs !== null) {
            $having[] = "dateDiff('millisecond', min(Start), max(End)) >= {minDuration:UInt32}";
            $params['minDuration'] = $filters->minDurationMs;
        }

        /*
         * The service filter matches the trace's ROOT service, which is all the
         * summary table knows. "Every trace that touched this service" would
         * need a semi-join back to otel_traces and would cost the trace list the
         * cheapness that is its whole point.
         */
        if ($filters->service !== null) {
            $having[] = 'max(RootService) = {service:String}';
            $params['service'] = $filters->service;
        }

        if ($filters->cursor !== null) {
            $having[] = 'min(Start) < {cursor:DateTime64(9)}';
            $params['cursor'] = $filters->cursor;
        }

        return $having;
    }

    /**
     * Run a query, turning an overload into a null rather than an exception.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<int, array<string, mixed>>|null
     */
    private function select(string $sql, array $params): ?array
    {
        try {
            return $this->client->select($sql, $params);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return null;
        }
    }

    /**
     * Normalise a summary row into the shape the frontend consumes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapTrace(array $row): array
    {
        $started = (string) ($row['Started'] ?? '');
        $ended = (string) ($row['Ended'] ?? '');

        return [
            'traceId' => (string) ($row['TraceId'] ?? ''),
            'rootName' => (string) ($row['TraceRootName'] ?? ''),
            'rootService' => (string) ($row['TraceRootService'] ?? ''),
            'startedAt' => $started,
            'endedAt' => $ended,
            'durationMs' => $this->durationMs($started, $ended),
            'spanCount' => (int) ($row['TraceSpanCount'] ?? 0),
            'errorCount' => (int) ($row['TraceErrorCount'] ?? 0),
        ];
    }

    /**
     * Normalise a span row into the shape the frontend consumes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapSpan(array $row): array
    {
        /** @var array<string, string> $attributes */
        $attributes = is_array($row['SpanAttributes'] ?? null) ? $row['SpanAttributes'] : [];

        return [
            'timestamp' => (string) ($row['Timestamp'] ?? ''),
            'traceId' => (string) ($row['TraceId'] ?? ''),
            'spanId' => (string) ($row['SpanId'] ?? ''),
            'parentSpanId' => (string) ($row['ParentSpanId'] ?? ''),
            'name' => (string) ($row['SpanName'] ?? ''),
            'kind' => (string) ($row['SpanKind'] ?? ''),
            'serviceName' => (string) ($row['ServiceName'] ?? ''),
            'durationMs' => round(((float) ($row['Duration'] ?? 0)) / 1_000_000, 3),
            'statusCode' => (string) ($row['StatusCode'] ?? ''),
            'statusMessage' => (string) ($row['StatusMessage'] ?? ''),
            'attributes' => $attributes,
            'events' => $this->events($row),
            'links' => $this->links($row),
        ];
    }

    /**
     * Rebuild a span's links from the parallel arrays (R12).
     *
     * Read exactly like the events beside them, and for the same reason: the
     * four columns are position aligned, so they are zipped by index and only up
     * to the shortest, and a row that lost part of one array yields fewer links
     * rather than a link wearing another one's target.
     *
     * A link is how an exporter says "this span belongs with that one" across a
     * trace boundary — Claude Code, for one, marks its `llm_request` spans
     * `link.type: parent_of` pointing at a span in another trace. Without these
     * columns a root span that *does* know where it came from renders as a lone
     * bar with nothing to say.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function links(array $row): array
    {
        $traceIds = is_array($row['Links.TraceId'] ?? null) ? array_values($row['Links.TraceId']) : [];
        $spanIds = is_array($row['Links.SpanId'] ?? null) ? array_values($row['Links.SpanId']) : [];
        $traceStates = is_array($row['Links.TraceState'] ?? null) ? array_values($row['Links.TraceState']) : [];
        $attributes = is_array($row['Links.Attributes'] ?? null) ? array_values($row['Links.Attributes']) : [];

        $count = min(count($traceIds), count($spanIds), count($traceStates), count($attributes));

        $links = [];

        for ($index = 0; $index < $count; $index++) {
            $linkAttributes = $attributes[$index];

            $links[] = [
                'traceId' => (string) $traceIds[$index],
                'spanId' => (string) $spanIds[$index],
                'traceState' => (string) $traceStates[$index],
                'attributes' => is_array($linkAttributes) ? $linkAttributes : [],
            ];
        }

        return $links;
    }

    /**
     * Rebuild a span's events from the parallel arrays (R12).
     *
     * The three columns are position aligned, so they are zipped by index and
     * only up to the shortest of them: a row that somehow lost part of one array
     * yields fewer events rather than an event wearing another one's attributes.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function events(array $row): array
    {
        $timestamps = is_array($row['Events.Timestamp'] ?? null) ? array_values($row['Events.Timestamp']) : [];
        $names = is_array($row['Events.Name'] ?? null) ? array_values($row['Events.Name']) : [];
        $attributes = is_array($row['Events.Attributes'] ?? null) ? array_values($row['Events.Attributes']) : [];

        $count = min(count($timestamps), count($names), count($attributes));

        $events = [];

        for ($index = 0; $index < $count; $index++) {
            $eventAttributes = $attributes[$index];

            $events[] = [
                'timestamp' => (string) $timestamps[$index],
                'name' => (string) $names[$index],
                'attributes' => is_array($eventAttributes) ? $eventAttributes : [],
            ];
        }

        return $events;
    }

    /**
     * The span of time between two ClickHouse timestamps, in milliseconds.
     */
    private function durationMs(string $started, string $ended): float
    {
        if ($started === '' || $ended === '') {
            return 0.0;
        }

        // getPreciseTimestamp(3) is already milliseconds. The sub-millisecond
        // tail of a DateTime64(9) is dropped here on purpose: this figure is the
        // one rendered in a list, and a span's own duration comes from the
        // Duration column rather than from subtracting two rendered strings.
        $start = Carbon::parse($started, 'UTC')->getPreciseTimestamp(3);
        $end = Carbon::parse($ended, 'UTC')->getPreciseTimestamp(3);

        return max(0.0, (float) ($end - $start));
    }

    /**
     * Render a list of strings the way ClickHouse expects an Array(String).
     *
     * @param  list<string>  $values
     */
    private function stringArrayParameter(array $values): string
    {
        $quoted = array_map(
            fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            $values,
        );

        return '['.implode(',', $quoted).']';
    }

    /**
     * Render a timestamp the way ClickHouse expects a DateTime64 parameter.
     */
    private function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->clone()->utc()->format('Y-m-d H:i:s.u');
    }
}
