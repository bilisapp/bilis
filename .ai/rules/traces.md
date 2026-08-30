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
columns (errors-only, min duration, the cursor) go in `HAVING`, not `WHERE`.

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
It can be stale, hand-edited, or written in the reader's own timezone, and a wrong one lands
the span query on an empty window — indistinguishable from expired spans unless you look
again. `TracesController::show()` retries once against the time `trace_summary` itself
reports whenever a supplied `ts` finds nothing, and everything downstream (the resource
lookup included) then uses the window that actually found spans. The optimisation survives —
a good `ts` still costs one bounded query — and the "spans have expired" message is left
meaning only what it says. Covered by "a stale timestamp falls back to the summary window".

## One resolver serves the trace page and the log viewer's panel
`TracesController::resolve()` is the single place that turns a `ts` into a summary, a span
set and the window they were found in — including the fallback above. The page and the
`traces.panel` XHR endpoint both go through it. Do not inline that logic into a second
surface: the fallback is subtle, the failure when two copies diverge is silent on both
sides, and the panel's whole job is to agree with the page it links to. `TraceQuery::summary()`
returns `array{trace, unavailable}` for the same reason — "no such trace" and "storage was
busy" must not collapse into one `null`.

## The waterfall query is always time-bounded, and the timestamp comes from the URL
`otel_traces` is sorted `(ProjectId, Timestamp, ServiceName)`, so a bare `TraceId = ...`
scans the whole retention window. `TraceQuery::spans()` always takes a `Carbon $around`
and brackets it (−1 min / +5 min, asymmetric because the timestamp usually comes from
somewhere *inside* the trace). Links into the waterfall carry `?ts=` — from a log row, a
trace list row, or a span. A pasted id with no `ts` resolves its time from `trace_summary`
first, which is a point lookup on that table's own key.

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
`TraceQuery::tail()` is the trace list's live poll. It keeps the ProjectId predicate and R11's `GROUP BY ProjectId, TraceId` but drops `Start <= {to}` entirely — a trace that arrives after the page loaded is by definition past the window's end, so keeping the bound guarantees an empty answer forever.

It also reads from `after - TAIL_OVERLAP_SECONDS`, not from `after`. `trace_summary` holds one row per trace per insert block, so a trace read while its spans are still arriving carries a partial span count; re-reading the last few seconds lets those counts settle. That only works because the client keys rows by trace id and replaces — never appends. The cursor is the newest `startedAt` the client holds, so the overlap does not creep.

A paging cursor must never reach a tail poll (`TraceFilters::withoutCursor()`): one reads backwards, the other forwards, and together they are always empty.

## Traces is two tabs sharing one query string
The traces surface is two pages: `traces.index` (the list, polling `traces.tail`) and `traces.latency` (per-service p95/p99). They render `traces/Index.vue` and `traces/Latency.vue`, both wearing `TracesTabs.vue` and `TracesToolbar.vue`.

Their shared props come from `TracesController::shared()` and every link between them carries the full filter query, built once by `traceFilterQuery()` in `resources/js/lib/traces.ts`. Switching tabs must never change the window being read; two query builders would drift, and the drift would look like a bug in the data.

`TracesToolbar`'s Live control is optional (`live` prop) and only the list passes it — a latency quantile that redrew every five seconds is unreadable, not current.

## A span link names a trace; naming one is not holding it
`TraceQuery::SPAN_COLUMNS` selects `Links.*` and `mapSpan()` rebuilds them the same position-aligned way events are (R12). They matter: a span whose parent lives in another trace cannot point at it through the tree, so exporters record a `parent_of` link instead. Claude Code does this on every `claude_code.llm_request` — one link each, to a trace id and span id unique per request that this instance will never hold (it is the remote side of the API call). Without the columns, such a span renders as a bar from nowhere.

`TracesController::show()` therefore resolves link targets through `TraceQuery::linkedTraces()` — a point lookup on `trace_summary`'s own key, re-aggregated like every read of that table — and passes `linkedTraces` to the page. A link is only offered as a way out when the target is actually stored; otherwise the UI says the trace is not stored *here*. Links back into the trace being read are dropped before the lookup. Never render a link as followable just because it exists.
