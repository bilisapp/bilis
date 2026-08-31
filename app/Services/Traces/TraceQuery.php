<?php

namespace App\Services\Traces;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Logs\LogQuery;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Reads traces out of ClickHouse for the viewer.
 *
 * Three tables, two shapes. The trace list never touches the spans: it asks
 * `trace_index` (keyed by the hour) which traces belong to the window, and
 * re-aggregates those from `trace_summary` (keyed by trace id, one row per
 * trace per insert block) — so browsing stays cheap no matter how wide the
 * traces are or how long the project has existed. The waterfall reads
 * `otel_traces`, always inside a time window, because the sort key leads with
 * `(ProjectId, Timestamp)` and a bare TraceId lookup would otherwise scan the
 * retention period.
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
     * The widest window a single waterfall will read, in seconds.
     *
     * When the summary is known the spans are read between the trace's own
     * Start and End, so the window is exactly as wide as the trace — a
     * minutes-long queue job or an hours-long agent session is read whole
     * rather than cut at the `ts` bracket above. This is the ceiling on that:
     * a trace longer than six hours has its tail cut and says so, because the
     * span read is bounded by `(ProjectId, Timestamp)` and an unbounded one
     * against a day-long trace is a scan of that day for every open.
     */
    public const SPAN_WINDOW_CAP_SECONDS = 6 * 3600;

    /**
     * How far before the list window the candidate query looks, in seconds.
     *
     * `trace_index` files a trace's blocks under the hour each block started
     * in, so a trace that began before the window but had spans start inside
     * it looks, from inside the window alone, like it began there. Looking
     * this far back lets the candidate query see such a trace's real start and
     * drop it before it takes a candidate slot. Deliberately the same figure as
     * the waterfall cap: a trace longer than six hours is exceptional on every
     * surface, and the margin below absorbs those.
     */
    private const CANDIDATE_LOOKBACK_SECONDS = self::SPAN_WINDOW_CAP_SECONDS;

    /**
     * How many candidate ids the list asks for beyond the page it will show.
     *
     * The candidate query nominates traces from `trace_index`; the page is
     * decided on `trace_summary`'s exact `min(Start)`. A candidate can still
     * fall out between the two — a trace longer than the lookback that
     * straddles the window's start — and this margin keeps such a fall-out from
     * shortening the page. Two hundred point lookups on the summary's own key
     * is what a page costs at most.
     */
    private const CANDIDATE_MARGIN = 150;

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
     * How long a span row lives, in days — `otel_traces`' TTL in SCHEMA.md.
     *
     * A summary lives 90 days, so a trace older than this is known but not
     * openable, and the list has to say so on the row rather than send the
     * reader to an empty waterfall. This figure is the DDL's, restated here so
     * a row can be judged without a second query.
     */
    public const SPAN_TTL_DAYS = 30;

    /**
     * The bucket widths the histogram may choose from, in seconds.
     *
     * The same ladder as {@see LogQuery}: the two strips sit on sibling pages
     * and a "1h" window must draw the same number of bars on both.
     */
    private const BUCKET_INTERVALS = [1, 5, 15, 30, 60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    private const TARGET_BUCKETS = 48;

    private const MAX_BUCKETS = 240;

    /**
     * How far back the service list looks when the selected window is shorter.
     *
     * The list answers "what does this project run?", which a fifteen-minute
     * window cannot: a service that traced an hour ago still belongs in it.
     */
    private const SERVICE_LOOKBACK_DAYS = 7;

    /**
     * The most service names the picker will ever be handed.
     */
    private const SERVICE_LIMIT = 200;

    /**
     * How long a resolved service list is reused, in seconds.
     */
    private const SERVICE_CACHE_SECONDS = 60;

    /**
     * The statuses a span may carry, as the exporter spells them (R10).
     */
    private const STATUS_ERROR = 'Error';

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository  $cache,
    ) {
    }

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

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($filters->from),
            'to' => $this->formatTimestamp($filters->to),
            'candidateFrom' => $this->formatTimestamp($filters->from->clone()->subSeconds(self::CANDIDATE_LOOKBACK_SECONDS)),
        ];

        /*
         * Two tables, one question. `trace_summary` is keyed (ProjectId,
         * TraceId), which makes a pasted id a point lookup and makes "the
         * newest traces in this hour" a scan of the project's whole 90 days —
         * TraceId is random, so a Start range prunes nothing. So the window is
         * asked of `trace_index`, keyed by the hour, which nominates candidate
         * ids; those are then re-aggregated here from *every* block they have
         * in trace_summary, by its own key. SCHEMA.md R13.
         *
         * Membership in the window is decided in HAVING, on the exact
         * min(Start) of the whole trace, never on a single block's Start in
         * WHERE. A trace whose later spans landed in a second insert block has
         * a second row with a later Start; a window boundary that falls between
         * the two blocks would otherwise pass only the late row and aggregate a
         * fragment — root name empty, half the spans, the wrong duration — and
         * the client, replacing rows by id, would overwrite the good row with it.
         */
        $candidateConditions = [
            'ProjectId IN {projectIds:Array(String)}',
            'Hour >= toStartOfHour({candidateFrom:DateTime64(9)})',
            'Hour <= {to:DateTime64(9)}',
        ];

        $candidateHaving = [
            'min(Start) >= {from:DateTime64(9)}',
            'min(Start) <= {to:DateTime64(9)}',
        ];

        $having = [
            'min(Start) >= {from:DateTime64(9)}',
            'min(Start) <= {to:DateTime64(9)}',
            ...$this->having($filters, $params),
        ];

        /*
         * The cursor pages backwards through the window and is exact on both
         * sides: a block that starts before the cursor belongs to a trace that
         * starts before it, so pushing it into the candidate query loses nothing.
         */
        if ($filters->cursor !== null) {
            $candidateConditions[] = 'Hour < {cursor:DateTime64(9)}';
            $candidateHaving[] = 'min(Start) < {cursor:DateTime64(9)}';
        }

        /*
         * Errors-only, minimum duration and root service can only be judged on
         * the aggregate, after the candidates are known, so when any of them is
         * set every id in the window is a candidate — the read is then bounded
         * by the window's width rather than the project's history, which is
         * still the point. Without them the candidate query hands over one page
         * plus a margin, newest first, and the page costs that many point lookups.
         */
        $candidateLimit = '';

        if (!$this->narrowsAfterAggregation($filters)) {
            $candidateLimit = 'LIMIT {candidateLimit:UInt32}';
            $params['candidateLimit'] = $filters->limit + self::CANDIDATE_MARGIN;
        }

        /*
         * The re-aggregation is not decoration. trace_summary is an
         * AggregatingMergeTree, so one trace holds one row *per insert block*
         * until a merge collapses them: a trace whose spans arrived in three
         * batches is three rows, each with a partial SpanCount and only one of
         * them carrying the root name. Reading it without this GROUP BY shows
         * the trace three times with the wrong numbers, and does so only under
         * load — which is exactly when nobody is looking. See SCHEMA.md R11.
         *
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
             WHERE ProjectId IN {projectIds:Array(String)}
               AND (ProjectId, TraceId) IN (
                   SELECT ProjectId, TraceId FROM trace_index
                   WHERE %s
                   GROUP BY ProjectId, TraceId
                   HAVING %s
                   ORDER BY min(Start) DESC
                   %s
               )
             GROUP BY ProjectId, TraceId
             HAVING %s
             ORDER BY Started DESC
             LIMIT {rowLimit:UInt32}',
            implode(' AND ', $candidateConditions),
            implode(' AND ', $candidateHaving),
            $candidateLimit,
            implode(' AND ', $having),
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

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($filters->from),
            'after' => $this->formatTimestamp($since),
        ];

        $having = [
            'min(Start) >= {from:DateTime64(9)}',
            ...$this->having($filters, $params),
        ];

        /*
         * A poll asks "which traces changed since I last looked", and a trace
         * changes whenever a block of its spans lands — so the candidate is any
         * trace with a block that ENDS after the cursor, and what comes back is
         * that trace's aggregate over every block it has. That is what the
         * client's replace-by-id merge is for: a trace first seen with two
         * spans and no root is re-sent whole once its root arrives, even a root
         * that started minutes earlier and only ended now — a session, a queue
         * job — which a "started after the cursor" test could never re-send,
         * because the root's start is older than the cursor by definition.
         *
         * The same row-level trap as the list applies. `Start > after` in WHERE
         * on trace_summary passed only a trace's late block and aggregated a
         * fragment; the window test is on the whole trace's min(Start), in
         * HAVING, and the candidates come from trace_index.
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
             WHERE ProjectId IN {projectIds:Array(String)}
               AND (ProjectId, TraceId) IN (
                   SELECT ProjectId, TraceId FROM trace_index
                   WHERE ProjectId IN {projectIds:Array(String)}
                     AND Hour >= toStartOfHour({from:DateTime64(9)})
                     AND End > {after:DateTime64(9)}
               )
             GROUP BY ProjectId, TraceId
             HAVING %s
             ORDER BY Started DESC
             LIMIT {rowLimit:UInt32}',
            implode(' AND ', $having),
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
        return $this->spansInWindow(
            $projectIds,
            $traceId,
            $around->clone()->subSeconds(self::SPAN_WINDOW_BEFORE_SECONDS),
            $around->clone()->addSeconds(self::SPAN_WINDOW_AFTER_SECONDS),
        );
    }

    /**
     * Fetch one trace's spans between two known instants.
     *
     * For the case where the trace's own extent is known — from its summary —
     * so the window is exactly the trace rather than a guess around a `ts`. A
     * trace longer than the `ts` bracket (a queue job, an agent session) is
     * read whole this way, up to {@see SPAN_WINDOW_CAP_SECONDS}; past that the
     * window is cut at the cap and `capped` says so, because the span read is
     * bounded by `(ProjectId, Timestamp)` and must stay bounded.
     *
     * @param list<string> $projectIds
     * @return array{spans: list<array<string, mixed>>, truncated: bool, unavailable: bool, capped: bool}
     */
    public function spansBetween(array $projectIds, string $traceId, Carbon $from, Carbon $to): array
    {
        $from = $from->clone()->utc();
        $to = $to->clone()->utc();

        if ($to->lessThan($from)) {
            $to = $from->clone();
        }

        $cap = $from->clone()->addSeconds(self::SPAN_WINDOW_CAP_SECONDS);
        $capped = $to->greaterThan($cap);

        return [
            ...$this->spansInWindow($projectIds, $traceId, $from, $capped ? $cap : $to),
            'capped' => $capped,
        ];
    }

    /**
     * The one span read: a trace's spans inside a time window, in tree order.
     *
     * @param list<string> $projectIds
     * @return array{spans: list<array<string, mixed>>, truncated: bool, unavailable: bool}
     */
    private function spansInWindow(array $projectIds, string $traceId, Carbon $from, Carbon $to): array
    {
        if ($projectIds === []) {
            return ['spans' => [], 'truncated' => false, 'unavailable' => false];
        }

        /*
         * SpanId breaks the tie between spans that started on the same
         * nanosecond — siblings fanned out by one parent routinely do — so the
         * tree is laid out the same way on every read rather than in whatever
         * order the parts happened to be merged in.
         */
        $sql = sprintf(
            'SELECT %s FROM otel_traces
             WHERE ProjectId IN {projectIds:Array(String)}
               AND Timestamp >= {from:DateTime64(9)}
               AND Timestamp <= {to:DateTime64(9)}
               AND TraceId = {traceId:String}
             ORDER BY Timestamp ASC, SpanId ASC
             LIMIT {rowLimit:UInt32}',
            self::SPAN_COLUMNS,
        );

        $rows = $this->select($sql, [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($from),
            'to' => $this->formatTimestamp($to),
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
     * Trace volume and failures over the filtered window, bucketed.
     *
     * The strip above the trace list. It answers the same two questions the
     * log histogram answers — "how much, and when did it go wrong" — so it is
     * bucketed on the same ladder and filled the same way: one entry per
     * bucket across the window, zeros included, so a quiet hour is drawn as a
     * quiet hour rather than as a gap the eye has to reason about.
     *
     * Read the way the list reads (R13): the window's candidate ids come from
     * `trace_index` by the hour, every candidate is re-aggregated over all its
     * blocks on `trace_summary` (R11), and only then is a trace placed in the
     * bucket its true `min(Start)` falls in. Counting `trace_index` rows
     * directly would be cheaper and wrong twice over — a trace whose spans
     * landed in two blocks would count twice, and the index carries no error
     * count at all. A trace is "failed" when any span in it failed, which is
     * what the list's errors-only filter means too.
     *
     * @param  list<string>  $projectIds
     * @return array{buckets: list<array{at: string, traces: int, errors: int}>, intervalSeconds: int, total: int, errors: int, unavailable: bool}
     */
    public function histogram(array $projectIds, TraceFilters $filters): array
    {
        $intervalSeconds = $this->bucketInterval($filters);

        if ($projectIds === []) {
            return $this->fillBuckets($filters, $intervalSeconds, []);
        }

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($filters->from),
            'to' => $this->formatTimestamp($filters->to),
            'candidateFrom' => $this->formatTimestamp($filters->from->clone()->subSeconds(self::CANDIDATE_LOOKBACK_SECONDS)),
            'bucketSeconds' => $intervalSeconds,
        ];

        $having = [
            'min(Start) >= {from:DateTime64(9)}',
            'min(Start) <= {to:DateTime64(9)}',
            ...$this->having($filters->withoutCursor(), $params),
        ];

        /*
         * The inner aliases deliberately avoid the column names — `Started`
         * and `Errors`, never `Start` or `ErrorCount` — for the R11 alias
         * reason: an aggregate named after its own column makes the HAVING
         * beside it resolve to sum(sum(...)) and fail with ILLEGAL_AGGREGATION.
         */
        $sql = sprintf(
            'SELECT
                toStartOfInterval(Started, toIntervalSecond({bucketSeconds:UInt32})) AS Bucket,
                count()             AS Traces,
                countIf(Errors > 0) AS FailedTraces
             FROM (
                 SELECT min(Start) AS Started, sum(ErrorCount) AS Errors
                 FROM trace_summary
                 WHERE ProjectId IN {projectIds:Array(String)}
                   AND (ProjectId, TraceId) IN (
                       SELECT ProjectId, TraceId FROM trace_index
                       WHERE ProjectId IN {projectIds:Array(String)}
                         AND Hour >= toStartOfHour({candidateFrom:DateTime64(9)})
                         AND Hour <= {to:DateTime64(9)}
                   )
                 GROUP BY ProjectId, TraceId
                 HAVING %s
             )
             GROUP BY Bucket
             ORDER BY Bucket ASC',
            implode(' AND ', $having),
        );

        $rows = $this->select($sql, $params);

        if ($rows === null) {
            $empty = $this->fillBuckets($filters, $intervalSeconds, []);
            $empty['unavailable'] = true;

            return $empty;
        }

        /** @var array<int, array{traces: int, errors: int}> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $bucket = (string)($row['Bucket'] ?? '');

            if ($bucket === '') {
                continue;
            }

            $key = Carbon::parse($bucket, 'UTC')->getTimestamp();
            $counts[$key] = [
                'traces' => ($counts[$key]['traces'] ?? 0) + (int)($row['Traces'] ?? 0),
                'errors' => ($counts[$key]['errors'] ?? 0) + (int)($row['FailedTraces'] ?? 0),
            ];
        }

        return $this->fillBuckets($filters, $intervalSeconds, $counts);
    }

    /**
     * The root services seen for these projects, for the toolbar's picker.
     *
     * Looks back {@see SERVICE_LOOKBACK_DAYS} when the window is shorter, the
     * way the log viewer's list does, and comes from the cheapest pair of
     * tables that can answer it: `trace_index` nominates the traces of the
     * last week by the hour (R13) and `trace_summary` is asked for the
     * DISTINCT non-empty `RootService` among them. No per-trace GROUP BY is
     * needed here — R11 is about counts and per-trace rows, and a block that
     * carried no root contributes `''`, which is filtered out rather than
     * mistaken for a service. Reading `otel_traces` instead would scan a week
     * of spans for a list of names.
     *
     * The picker is a suggestion, not a constraint: the field stays free text,
     * because the latency tab matches *any* span's service and a service that
     * never roots a trace is still worth asking about.
     *
     * An overloaded ClickHouse yields an empty list — the picker degrades to
     * a plain text field rather than taking the page down with it.
     *
     * @param list<string> $projectIds
     * @return list<string>
     */
    public function services(array $projectIds, TraceFilters $filters): array
    {
        if ($projectIds === []) {
            return [];
        }

        /*
         * Both bounds are snapped to the minute so the same window keeps the
         * same cache key for a whole minute — a "last 7 days" lower bound that
         * moved with every request would never hit the cache.
         */
        $now = Carbon::now();
        $from = $filters->from->clone()->min($now->clone()->subDays(self::SERVICE_LOOKBACK_DAYS))->startOfMinute();
        $to = $filters->to->clone()->max($now)->startOfMinute()->addMinute();

        $params = [
            'projectIds' => $this->stringArrayParameter($projectIds),
            'from' => $this->formatTimestamp($from),
            'to' => $this->formatTimestamp($to),
            'rowLimit' => self::SERVICE_LIMIT,
        ];

        $sql = 'SELECT DISTINCT RootService
             FROM trace_summary
             WHERE ProjectId IN {projectIds:Array(String)}
               AND RootService != \'\'
               AND (ProjectId, TraceId) IN (
                   SELECT ProjectId, TraceId FROM trace_index
                   WHERE ProjectId IN {projectIds:Array(String)}
                     AND Hour >= toStartOfHour({from:DateTime64(9)})
                     AND Hour <= {to:DateTime64(9)}
               )
             ORDER BY RootService ASC
             LIMIT {rowLimit:UInt32}';

        $key = 'traces.services.' . sha1(implode(',', $projectIds) . '|' . $params['from'] . '|' . $params['to']);

        /** @var list<string> $services */
        $services = $this->cache->remember(
            $key,
            self::SERVICE_CACHE_SECONDS,
            function () use ($sql, $params): array {
                $rows = $this->select($sql, $params);

                if ($rows === null) {
                    return [];
                }

                return array_values(array_filter(array_map(
                    fn(array $row): string => (string)($row['RootService'] ?? ''),
                    $rows,
                ), fn(string $name): bool => $name !== ''));
            },
        );

        return $services;
    }

    /**
     * Choose the narrowest bucket width that keeps the bar count near the target.
     */
    private function bucketInterval(TraceFilters $filters): int
    {
        $span = max(1, $filters->to->getTimestamp() - $filters->from->getTimestamp());

        foreach (self::BUCKET_INTERVALS as $interval) {
            if ((int)ceil($span / $interval) <= self::TARGET_BUCKETS) {
                return $interval;
            }
        }

        return (int)max(
            self::BUCKET_INTERVALS[count(self::BUCKET_INTERVALS) - 1],
            (int)ceil($span / self::MAX_BUCKETS),
        );
    }

    /**
     * Expand the sparse counts into one entry per bucket across the window.
     *
     * @param array<int, array{traces: int, errors: int}> $counts
     * @return array{buckets: list<array{at: string, traces: int, errors: int}>, intervalSeconds: int, total: int, errors: int, unavailable: bool}
     */
    private function fillBuckets(TraceFilters $filters, int $intervalSeconds, array $counts): array
    {
        $start = intdiv($filters->from->getTimestamp(), $intervalSeconds) * $intervalSeconds;
        $end = $filters->to->getTimestamp();

        $buckets = [];
        $total = 0;
        $errors = 0;

        for ($at = $start; $at <= $end && count($buckets) < self::MAX_BUCKETS; $at += $intervalSeconds) {
            $traces = $counts[$at]['traces'] ?? 0;
            $failed = $counts[$at]['errors'] ?? 0;

            $buckets[] = [
                'at' => Carbon::createFromTimestampUTC($at)->format('Y-m-d H:i:s.u'),
                'traces' => $traces,
                'errors' => $failed,
            ];

            $total += $traces;
            $errors += $failed;
        }

        return [
            'buckets' => $buckets,
            'intervalSeconds' => $intervalSeconds,
            'total' => $total,
            'errors' => $errors,
            'unavailable' => false,
        ];
    }

    /**
     * Whether any filter can only be judged on the aggregated trace.
     *
     * Error count, duration and the root service are all unknown until every
     * block of a trace has been summed, so when one of them is set the list
     * cannot take a page of candidates and hope enough survive — it has to
     * aggregate every trace in the window and filter after.
     */
    private function narrowsAfterAggregation(TraceFilters $filters): bool
    {
        return $filters->errorsOnly || $filters->minDurationMs !== null || $filters->service !== null;
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
            /*
             * Summaries outlive spans (90 days against 30), so a trace older
             * than the span TTL is known but has no waterfall. Judged here,
             * once, so every surface — the list, a poll, a link target — says
             * the same thing about the same row.
             */
            'spansExpired' => $this->spansExpired($started),
        ];
    }

    /**
     * Whether a trace that started at the given time has outlived its spans.
     *
     * Expiry is lazy on the span table, so a trace a few hours past the TTL
     * may still open; the row errs towards "gone" because the alternative is
     * a link that lands on an empty waterfall with no explanation.
     */
    private function spansExpired(string $started): bool
    {
        if ($started === '') {
            return false;
        }

        return Carbon::parse($started, 'UTC')->lessThan(Carbon::now()->subDays(self::SPAN_TTL_DAYS));
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
