---
paths:
  - 'app/Services/Traces/**'
  - 'app/Services/Ingest/OtlpTraceMapper.php'
  - 'app/Services/Ingest/SpanWriter.php'
  - 'app/Services/Ingest/SpanSemantics.php'
  - 'app/Http/Controllers/TracesController.php'
---

# Traces

## Span status and kind are the exporter's literals, never the proto's enum names
`SpanSemantics` normalises both onto pdata's `String()` forms — `Unset`/`Ok`/`Error` and
`Unspecified`/`Internal`/`Server`/`Client`/`Producer`/`Consumer` — because that is what
`exporter_traces.go` writes, and because `trace_summary_mv` counts errors with
`countIf(StatusCode = 'Error')`. Storing `STATUS_CODE_ERROR` reports zero errors on every
trace forever and fails nothing. The wire may spell these as enum integers or enum names;
both are accepted going in, only the literals above are ever stored. SCHEMA.md R10.

## Every read of trace_summary re-aggregates
It is an `AggregatingMergeTree`: one row per trace *per insert block* until a merge
collapses them. Every query is `sum(SpanCount)`, `sum(ErrorCount)`, `min(Start)`,
`max(End)`, `max(RootName)`, `max(RootService)` with `GROUP BY ProjectId, TraceId`.
Without it a trace whose spans arrived in three batches is returned three times with
partial counts, and only under the load that splits batches. Filters computed from those
columns (errors-only, min duration, the cursor, **and the time window**) go in `HAVING`,
not `WHERE`. `Start >= {from}` in `WHERE` is a row-level test that passes a trace's late
block alone when the boundary falls between its blocks — reproduced live as root name `''`,
half the spans and a 2 s duration for a 31 s trace, which the client's replace-by-id merge
then wrote over the good row. SCHEMA.md R11, R13.

## The list nominates from trace_index and decides on trace_summary

`trace_summary`'s key `(ProjectId, TraceId)` makes a pasted id a point lookup and makes
"newest traces in this hour" a full scan of the project's 90 days (TraceId is random; a
`Start` range prunes nothing). So `TraceQuery::list()` and `tail()` ask `trace_index`
(`ORDER BY (ProjectId, Hour, TraceId)`, one thin row per trace per hour a block of its
spans started in) which ids belong to the window — key-pruned by the hour — and then
re-aggregate exactly those ids from `trace_summary` over every block, window in `HAVING`.
Never answer anything from `trace_index` alone: it holds no counts and no root, and a
trace that began before the window can have a row inside it.

The list's candidate query looks back `SPAN_WINDOW_CAP_SECONDS` (6 h) before `from` so a
straddling trace's real start is visible and it never takes a candidate slot, and asks for
`limit + CANDIDATE_MARGIN` ids; with a filter that can only be judged on the aggregate
(errors-only, min duration, root service) it asks for every id in the window instead, so
pagination stays exact. `summary()`, `linkedTraces()` and `hasAnyTraces()` do not go through
the index — they are already point lookups on the summary's own key. `serviceLatency()`
reads span rows, not the summary, and is unaffected. SCHEMA.md R13.

The index is filled by `trace_index_mv` and, for what existed before it, by
`0009_backfill_trace_index.sql`, which re-runs on every boot and guards itself by comparing
distinct trace counts with the summary — not by emptiness, because a rolling deploy feeds
the new view before the backfill runs and an "empty" test would skip the history silently.

Never alias an aggregate to its own column name: `sum(ErrorCount) AS ErrorCount` makes the
`sum(ErrorCount)` in `HAVING` resolve to `sum(sum(ErrorCount))` and ClickHouse raises
`ILLEGAL_AGGREGATION`. That is why `TraceQuery` projects `TraceSpanCount`,
`TraceErrorCount`, `TraceRootName` and `TraceRootService`. It shipped broken once because
every test used `Http::fake`, which never executes the SQL — filter changes need a live
assertion. SCHEMA.md R11.

## A trace's End is the last span's end, not the last span's start
`Timestamp` is a span's start, so `trace_summary_mv` computes
`max(Timestamp + toIntervalNanosecond(Duration))`. Using `max(Timestamp)` understates
every trace's duration by the length of its final span, plausibly enough that nobody
notices — a 4.29-second trace reported 168ms.

## A `ts` in the URL is a hint, not a fact

It can be stale, hand-edited, written in the reader's own timezone, or taken from a log
line a minute into the trace. It used to bound the span query (−1 min / +5 min) whenever it
was present, with a retry against the summary only when that bracket came back *empty* —
so a `ts` more than a minute after the root, or any trace longer than five minutes (a queue
job, a Claude Code session), produced a rootless partial waterfall with no fallback, because
a partial result is not an empty one.

Now `TracesController::resolve()` fetches the summary first and, **whenever a summary
exists, reads spans between the trace's own `Started − 1 s` and `Ended + 1 s`**
(`TraceQuery::spansBetween()`, capped at `SPAN_WINDOW_CAP_SECONDS` = 6 h; a cut window is
reported as `truncated`, the same flag the span-count cap sets). The `ts` is used only when
there is no summary to trust — none stored, or storage too busy to say — and is then
bracketed by `spans()` the old way. `rootResource()` still brackets `around`, which is now
always the trace's start. Covered by "a stale timestamp never narrows the window the summary
already reports", "a trace longer than the timestamp bracket is read whole" and "a trace
longer than the window cap is cut at the cap and says so".

## One resolver serves the trace page and the log viewer's panel
`TracesController::resolve()` is the single place that turns a `ts` into a summary, a span
set and the window they were found in — including the fallback above. The page and the
`traces.panel` XHR endpoint both go through it. Do not inline that logic into a second
surface: the fallback is subtle, the failure when two copies diverge is silent on both
sides, and the panel's whole job is to agree with the page it links to. `TraceQuery::summary()`
returns `array{trace, unavailable}` for the same reason — "no such trace" and "storage was
busy" must not collapse into one `null`.

## The waterfall query is always time-bounded, and the bounds come from the summary
`otel_traces` is sorted `(ProjectId, Timestamp, ServiceName)`, so a bare `TraceId = ...`
scans the whole retention window. Every span read goes through one private method with a
`from` and a `to`: `TraceQuery::spansBetween()` takes the trace's own extent (from the
summary, capped at 6 h) and `spans()` takes a `Carbon $around` and brackets it (−1 min /
+5 min, asymmetric because the timestamp usually comes from somewhere *inside* the trace).
Links into the waterfall still carry `?ts=` — from a log row, a trace list row, or a span —
but it only bounds the query when there is no summary. Spans are ordered
`Timestamp ASC, SpanId ASC`, so siblings that started on the same nanosecond lay out the
same way on every read.

## No span is ever dropped from the tree
`SpanTree::flatten()` renders a span whose parent is missing at root level, and sweeps up
anything caught in a parent cycle. A missing parent is normal — aged out past the 30-day
span TTL, still in flight, or beyond `TraceQuery::SPAN_LIMIT` — and a span that was
queried and returned must never be silently invisible. The tree is flattened server-side
with a `depth` on each row, so the page ships a flat list rather than building a
2,000-node component tree.

## Summaries outlive spans, and the UI has to say so
90 days against 30. A `trace_summary` row whose spans have expired is a designed state:
the trace stays in the list with its waterfall link disabled, and the waterfall page
explains rather than showing an empty chart. Distinguish it from "no such trace" (no
summary at all) and from "storage is busy" (`unavailable`) — three states, three messages.

## Events and links are position-aligned
The six parallel arrays are built in one pass and read by index up to the shortest of
them. `Events.Attributes` / `Links.Attributes` are `Array(Map)`: the outer `[]` is right,
each element must serialize as `{}`. `SpanWriter::normalise()` is the only implementation —
go through it, tests included, or you are testing a shape ClickHouse throws away after it
has already acked the insert. SCHEMA.md R12.

## The trace tail drops the window's upper bound and overlaps its cursor

`TraceQuery::tail()` is the trace list's live poll. It keeps the ProjectId predicate and R11's
`GROUP BY ProjectId, TraceId` but has no upper bound — a trace that arrives after the page loaded is by definition past
the window's end, so keeping the bound guarantees an empty answer forever. The window's *start* still holds, in `HAVING`
on `min(Start)`.

It nominates on **`End > {after}`** from `trace_index`, not on `Start`, and returns the whole aggregate of every
nominated trace. A poll asks "which traces changed since I last looked", and a trace changes whenever a block of its
spans lands: a root span that started minutes before the cursor and ended after it (a session, a queue job) is caught by
its block's `End` and could never be caught by its `Start`. Re-sending the full aggregate is the point — the client keys
rows by trace id and replaces, never appends — and it is what lets a trace first seen with two spans and no root settle
into its real counts and name.

It also reads from `after - TAIL_OVERLAP_SECONDS`, not from `after`, so a block acked just before the cursor is still
re-read once. The cursor is the newest `startedAt` the client holds, so the overlap does not creep.

A paging cursor must never reach a tail poll (`TraceFilters::withoutCursor()`): one reads backwards, the other forwards, and together they are always empty.

## Traces is two tabs sharing one query string
The traces surface is two pages: `traces.index` (the list, polling `traces.tail`) and `traces.latency` (per-service p95/p99). They render `traces/Index.vue` and `traces/Latency.vue`, both wearing `TracesTabs.vue` and `TracesToolbar.vue`.

Their shared props come from `TracesController::shared()` and every link between them carries the full filter query, built once by `traceFilterQuery()` in `resources/js/lib/traces.ts`. Switching tabs must never change the window being read; two query builders would drift, and the drift would look like a bug in the data.

`TracesToolbar`'s Live control is optional (`live` prop) and only the list passes it — a latency quantile that redrew every five seconds is unreadable, not current.

## A span link names a trace; naming one is not holding it
`TraceQuery::SPAN_COLUMNS` selects `Links.*` and `mapSpan()` rebuilds them the same position-aligned way events are (R12). They matter: a span whose parent lives in another trace cannot point at it through the tree, so exporters record a `parent_of` link instead. Claude Code does this on every `claude_code.llm_request` — one link each, to a trace id and span id unique per request that this instance will never hold (it is the remote side of the API call). Without the columns, such a span renders as a bar from nowhere.

`TracesController::show()` therefore resolves link targets through `TraceQuery::linkedTraces()` — a point lookup on `trace_summary`'s own key, re-aggregated like every read of that table — and passes `linkedTraces` to the page. A link is only offered as a way out when the target is actually stored; otherwise the UI says the trace is not stored *here*. Links back into the trace being read are dropped before the lookup. Never render a link as followable just because it exists.

## The histogram and the service picker read the index too, and differently

`TraceQuery::histogram()` is the list's read (R13) with a bucketing query wrapped around it:
candidates from `trace_index` by the hour, every candidate re-aggregated on `trace_summary`
with `GROUP BY ProjectId, TraceId` and the window plus the user filters in `HAVING`, and only
then `toStartOfInterval(min(Start))`. Counting `trace_index` rows directly is tempting and wrong
twice: a trace whose spans landed in two blocks is two index rows, and the index has no error
count. The inner aliases are `Started` / `Errors`, never `Start` / `ErrorCount` (R11's alias
trap). Its bucket ladder is `LogQuery`'s, on purpose — the two strips sit on sibling pages.

`TraceQuery::services()` needs no per-trace `GROUP BY`: it wants the DISTINCT non-empty
`RootService` among the last week's traces, and a rootless block contributes `''`, which is
filtered rather than aggregated away. It still nominates through `trace_index`, because a bare
scan of `trace_summary` for a week is a scan of ninety days. Cached 60 s on a minute-snapped
window, like the log picker. The names are suggestions in a datalist, not a select: the field
accepts a service that never roots a trace, because the latency tab matches any span's service.

## `spansExpired` is decided in `mapTrace()`, once

A summary older than `SPAN_TTL_DAYS` (30, the `otel_traces` TTL) is flagged on the row itself,
so the list, the poll and every link say the same thing about the same trace without a second
query. The list row renders it as a link-less row that explains itself.
