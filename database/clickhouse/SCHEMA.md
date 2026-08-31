# Bilis — logs and traces schema, and the rules governing them

Status: logs and traces. ClickHouse + Laravel (Octane/FrankenPHP), Traefik via Coolify,
single OVH box.

---

## 1. Pinned reference

**Pin an exact collector version tag and record it here. Diff against that tag, never `main`.**

```
otel-collector-contrib: v0.159.0
schema source: exporter/clickhouseexporter/internal/sqltemplates/
                 (logs_table.sql, logs_insert.sql, traces_table.sql, traces_insert.sql)
                 plus exporter_traces.go for the values written into SpanKind/StatusCode
clickhouse floor:   >= 26.2 (raised 2026-08-30; see R5 and the migration note below)
clickhouse verified against: 26.9.1.158
last verified: 2026-08-30
```

> **The production server's version is not recorded here yet.** Nothing in the repo pins a
> ClickHouse image — no compose file, no CI service container — so the floor above is the
> one this code requires, not one the deployment is known to meet. Record the real
> `SELECT version()` from production here. `clickhouse:materialize-index` refuses to run
> below 26.2, which is the guard, but it is a guard rather than a substitute for knowing.

The shipped DDL uses the **>= 26.2** branch of R5: a `text` index on `lower(Body)`. The
floor was raised on 2026-08-30, after `otel_logs` already held production data, so the
change is an online migration rather than an edit — `0005_alter_otel_logs_body_index.sql`
swaps the index definition (instant, metadata only) and
`php artisan clickhouse:materialize-index` rebuilds the index files for the parts written
before the swap. Between the two, body search keeps working by full scan on old parts.
Measured on 26.9: the ALTER is instant, queries never fail during the gap, and after the
mutation `EXPLAIN indexes = 1` reports `ReadFromTextIndexCount`.

The exporter schema is actively evolving. Every claim below is only true against a
specific tag. Re-diff on every collector upgrade and update the date above.

---

## 2. Tables

### 2.1 `otel_logs`

```sql
CREATE TABLE otel_logs
(
    Timestamp          DateTime64(9, 'UTC')                CODEC(Delta(8), ZSTD(1)),
    TraceId            String                              CODEC(ZSTD(1)),
    SpanId             String                              CODEC(ZSTD(1)),
    TraceFlags         UInt8,
    SeverityText       LowCardinality(String)              CODEC(ZSTD(1)),
    SeverityNumber     UInt8,
    ServiceName        LowCardinality(String)              CODEC(ZSTD(1)),
    Body               String                              CODEC(ZSTD(1)),
    ResourceSchemaUrl  LowCardinality(String)              CODEC(ZSTD(1)),
    ResourceAttributes Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    ScopeSchemaUrl     LowCardinality(String)              CODEC(ZSTD(1)),
    ScopeName          String                              CODEC(ZSTD(1)),
    ScopeVersion       LowCardinality(String)              CODEC(ZSTD(1)),
    ScopeAttributes    Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    LogAttributes      Map(LowCardinality(String), String) CODEC(ZSTD(1)),
    EventName          String                              CODEC(ZSTD(1)),

    -- Bilis addition. Written explicitly by Bilis from the authenticated API key.
    -- DEFAULT '' only keeps stock-exporter INSERTs valid; it does NOT populate them.
    ProjectId          LowCardinality(String) DEFAULT ''   CODEC(ZSTD(1)),

    INDEX idx_trace_id         TraceId                       TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_res_attr_key     mapKeys(ResourceAttributes)   TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_res_attr_value   mapValues(ResourceAttributes) TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_scope_attr_key   mapKeys(ScopeAttributes)      TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_scope_attr_value mapValues(ScopeAttributes)    TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_log_attr_key     mapKeys(LogAttributes)        TYPE bloom_filter(0.01)  GRANULARITY 1,
    INDEX idx_log_attr_value   mapValues(LogAttributes)      TYPE bloom_filter(0.01)  GRANULARITY 1,

    INDEX idx_service ServiceName TYPE set(100) GRANULARITY 4,

    -- ClickHouse >= 26.2. The expression stays lower(Body): the tokenizer
    -- splits but does NOT fold case. See R5.
    INDEX idx_lower_body lower(Body) TYPE text(tokenizer = 'splitByNonAlpha') GRANULARITY 8
)
-- Deliberate divergence from upstream (2): Timestamp is DateTime64(9, 'UTC')
-- rather than naive DateTime64(9). A naive column parses AND renders in the
-- server/session timezone, so a server not running UTC silently skews stored
-- epochs and returns shifted strings. The exporter inserts epochs, so the
-- explicit timezone does not affect ingest compatibility. The Laravel client
-- additionally pins session_timezone=UTC on every request.
-- Deliberate divergence from upstream (1). Upstream leads with
-- toStartOfFiveMinutes(Timestamp) because it has no tenant column; with
-- ProjectId leading, the bucket adds little and breaks read-in-order for
-- live tail and the default "latest N logs" view. idx_service compensates
-- for service-filtered queries.
ENGINE = MergeTree
PARTITION BY toDate(Timestamp)
ORDER BY (ProjectId, Timestamp, ServiceName)
TTL toDateTime(Timestamp) + toIntervalDay(30)
SETTINGS index_granularity = 8192,
         ttl_only_drop_parts = 1,
         non_replicated_deduplication_window = 1000;
```

### 2.2 `otel_traces`

One row per span. Column names and types are the exporter's `traces_table.sql`
(`ScopeName` and `ScopeVersion` included — the exporter's INSERT names them, so a table
without them rejects a stock export). `Duration` is `UInt64` nanoseconds. `Events` and
`Links` are `Nested`, which stores exactly the `Events.Timestamp` / `Events.Name` /
`Events.Attributes` columns the insert addresses.

Ours, as ever: `ORDER BY`, `PARTITION BY`, `TTL`, indexes and `ProjectId`. The sort key is
the same `(ProjectId, Timestamp, ServiceName)` the logs table uses, a deliberate
divergence from upstream's `(ServiceName, SpanName, toDateTime(Timestamp))` — upstream has
no tenant column, and with `ProjectId` leading a trace list or a waterfall is a seek.

Both timestamps carry an explicit zone: `Timestamp DateTime64(9, 'UTC')` and, inside the
`Nested`, `Events.Timestamp DateTime64(9, 'UTC')`. The second shipped naive at first, and
that is a bug on any host not running UTC: `session_timezone` governs parsing but not how a
naive `DateTime64` is *rendered* (verified on 26.9), so event ticks came back in the server
zone while the span around them came back in UTC, and the UI — which appends `Z` — placed
them wrong. `0006_alter_otel_traces_events_timestamp.sql` converges a deployed table with
`ALTER TABLE otel_traces MODIFY COLUMN IF EXISTS \`Events.Timestamp\` Array(DateTime64(9, 'UTC'))`
— the `Nested` sub-column is addressed by its dotted name and its `Array(...)` type; the
change is metadata only (the stored epoch is the same either way) and instant.

Shipped DDL: `database/clickhouse/0002_create_otel_traces_table.sql`, `0006_alter_otel_traces_events_timestamp.sql`.

### 2.3 `trace_summary` and `trace_summary_mv`

One row per trace per insert block, maintained by a materialized view over `otel_traces`.
It powers the trace list and narrows a bare-TraceId lookup to a time range before the span
table is touched at all. `Start` is the earliest span's start; `End` is the latest span's
*end*, which is why the view adds `Duration` to `Timestamp` rather than taking
`max(Timestamp)`. Retention is 90 days against the spans' 30, so a summary
deliberately outlives its spans — see R11 and R9.

Shipped DDL: `database/clickhouse/0003_create_trace_summary_table.sql` and
`0004_create_trace_summary_mv.sql`. **Read R11 before editing either.** Every choice in
them fails silently when changed.

### 2.4 `trace_index`, `trace_index_mv` and the backfill

The trace list's *time* index. `trace_summary` is keyed `(ProjectId, TraceId)`, which
makes a pasted id a point lookup and makes "the newest traces in this window" a scan of
the project's whole 90 days: `TraceId` is random, so a `Start` range prunes nothing
(measured: `Granules N/N`, only `ProjectId` used from the primary key, on every page load
and every five-second poll). This table is keyed by the hour instead and holds, per trace
per hour a block of its spans started in, that block's first span start and last span end
— no counts, no root, nothing a reader answers from alone.

```sql
CREATE TABLE trace_index
(
    ProjectId LowCardinality(String),
    Hour      DateTime('UTC'),                                   -- toStartOfHour of the block's earliest span
    TraceId   String                                          CODEC(ZSTD(1)),
    Start     SimpleAggregateFunction(min, DateTime64(9, 'UTC')),
    End       SimpleAggregateFunction(max, DateTime64(9, 'UTC'))
)
ENGINE = AggregatingMergeTree
PARTITION BY toDate(Hour)
ORDER BY (ProjectId, Hour, TraceId)
TTL Hour + toIntervalDay(90)
SETTINGS index_granularity = 8192, ttl_only_drop_parts = 1;

CREATE MATERIALIZED VIEW trace_index_mv TO trace_index AS
SELECT ProjectId,
       toStartOfHour(min(Timestamp)) AS Hour,
       TraceId,
       min(Timestamp)                AS Start,
       max(Timestamp + toIntervalNanosecond(Duration)) AS End
FROM otel_traces
WHERE TraceId != ''
GROUP BY ProjectId, TraceId;
```

`PARTITION BY` is safe here where it is not on `trace_summary` (R11): `Hour` is part of the
key, so a merge cannot move a row's hour. Retention matches the summary, so every
candidate the index nominates is still there to aggregate.

`0009_backfill_trace_index.sql` fills it from `trace_summary` (which outlives the spans)
on a deployed database. It re-runs on every boot and guards itself by comparing distinct
trace counts between the two tables rather than testing for emptiness — see R13 for why.

Shipped DDL: `0007_create_trace_index_table.sql`, `0008_create_trace_index_mv.sql`,
`0009_backfill_trace_index.sql`. Governed by R13.


---

## 3. Rules

### R1 — Column names and types are not yours to change

The exporter's `INSERT` uses an explicit column list. Names and types must match the
pinned tag exactly, PascalCase included. `EventName` is `String`, not
`LowCardinality(String)`.

Everything else is yours: `ORDER BY`, `PARTITION BY`, `TTL`, indexes, engine, and any
**additional** column with a `DEFAULT`.

Corollary: no typed attribute maps (`LogAttributes_int` etc.). Numeric predicates go
through `toFloat64OrNull(LogAttributes['x'])` — unindexed, but compatible.

### R2 — ProjectId must be written, not defaulted

`DEFAULT ''` makes a stock-exporter INSERT *valid*, not *correct*. Any row written by an
unmodified exporter lands in a single degenerate `''` prefix and gets no locality at all.

Pick one and document it:

- **Bilis writes `ProjectId` explicitly in its own INSERT.** (Default choice — avoids the
  question of whether a MATERIALIZED column may sit in the sorting key.)
- **`MATERIALIZED ResourceAttributes['bilis.project.id']`**, with a trusted gateway that
  *overwrites* that attribute from authentication. Verify sorting-key support on your
  ClickHouse version before relying on it.

Never read `ProjectId` from the payload under any circumstances.

### R3 — ProjectId is clustering, not isolation

Leading the sort key with `ProjectId` buys locality and compression. It buys **zero**
security. Isolation requires authentication *plus* one of:

- a server-side `ProjectId` predicate the user cannot remove,
- a ClickHouse row policy,
- separate tables or databases per tenant.

Never describe the sort key as a tenancy boundary, in code comments or in docs.

### R4 — Query the sort key correctly

Key is `(ProjectId, Timestamp, ServiceName)`. Range predicate:

```sql
WHERE ProjectId = {project:String}
  AND Timestamp >= {from:DateTime64(9)}
  AND Timestamp <  {to:DateTime64(9)}
ORDER BY Timestamp DESC
```

Live tail and "latest N" omit the range entirely and read in order:
`WHERE ProjectId = {project:String} ORDER BY Timestamp DESC LIMIT {n:UInt32}`.

Build this in one query-builder method. User filters append; they never replace the
ProjectId predicate.

If the bucket is ever reintroduced, every query must constrain both
`toStartOfFiveMinutes(Timestamp)` (with the bound itself truncated) and raw `Timestamp`
— omitting the truncation silently drops rows at range edges.

### R5 — Text search, and the `lower()` wrapper that survives the upgrade

**The shipped branch (ClickHouse >= 26.2).**

```sql
INDEX idx_lower_body lower(Body) TYPE text(tokenizer = 'splitByNonAlpha') GRANULARITY 8
```

```sql
-- one whole token
hasAnyTokens(lower(Body), [lower({search:String})])
-- anything else: substring "contains" mode, still on the indexed expression
lower(Body) LIKE lower({search:String})
```

**The index expression and the query expression must match character for character**, or
ClickHouse silently skips the index and the query degrades to a full scan with no error.

**`lower()` stays on both sides.** An earlier version of this rule said to drop the
wrapper above 26.2 because "the tokenizer normalises". It does not. Measured on 26.9:

```
hasAnyTokens(Body,        ['connect'])  -> 0   -- the stored line says "CONNECT"
hasAnyTokens(lower(Body), ['connect'])  -> 1
```

`splitByNonAlpha` splits on non-alphanumerics; it does not fold case. Dropping the wrapper
loses case-insensitive search *and* stops using the index, and neither failure announces
itself. Prove index use rather than assuming it:

```sql
SELECT count() FROM otel_logs WHERE hasAnyTokens(lower(Body), ['connect'])
SETTINGS force_data_skipping_indices = 'idx_lower_body';   -- errors if unused
```

The same probe raises `INDEX_NOT_USED` for the unwrapped form and for `Body ILIKE`, which
is why the fallback is written `lower(Body) LIKE lower(...)` rather than `Body ILIKE ...`:
the two fold case identically (both ASCII only) and return identical rows, but only the
first can read the index.

`tokenizer` is mandatory and its valid names are `splitByNonAlpha` and `array`. There is
no `default`.

**The `< 26.2` branch, for reference.** `INDEX idx_lower_body lower(Body) TYPE
tokenbf_v1(32768, 3, 0)`, queried with `hasToken(lower(Body), lower({q:String}))`. This is
what shipped until 2026-08-30. Index and query change together or not at all.

**Migrating a table that already has data.** `CREATE TABLE IF NOT EXISTS` cannot alter an
existing table, so the swap lives in its own file:

1. `clickhouse:migrate` applies `0005_alter_otel_logs_body_index.sql` — one guarded
   `ALTER … DROP INDEX IF EXISTS …, ADD INDEX IF NOT EXISTS …`. Instant, metadata only,
   idempotent, and safe to run concurrently (docker-entrypoint.sh runs migrate once per
   container role, so a deploy issues it up to three times at once).
2. New parts carry the text index immediately. Pre-existing parts answer body search by
   full scan — correct, just unaccelerated — until an operator runs
   `php artisan clickhouse:materialize-index`.

`MATERIALIZE INDEX` is deliberately **not** part of `clickhouse:migrate`: it rewrites index
files across every existing part, and re-issuing that on each boot would re-mutate the
whole table while it is also ingesting.

### R6 — Derived columns must not diverge from the partition key

Do not reintroduce `TimestampTime` / `TimestampDate`. A `DEFAULT`-derived column is an
independent stored value: a predicate on `Timestamp` cannot prune a partition keyed on
`toDate(TimestampTime)`. Partition, TTL and sort key all use `Timestamp` directly. The partition key stays
`toDate(Timestamp)`, unchanged by the sort key edit.

### R7 — MergeTree now, Replicated when the second node arrives

Single node runs plain `MergeTree`. `ReplicatedMergeTree` needs Keeper, and a Replicated
table with unreachable Keeper goes **read-only** — on one box that adds a failure mode
without adding redundancy.

`non_replicated_deduplication_window = 1000` gives idempotent retries today, which is the
main thing replication would otherwise buy. Collectors retry on timeout; without this,
slow-acked batches become duplicate log lines.

Converting later is an hour of careful work, not a data migration:

- `ATTACH TABLE ... AS REPLICATED`, or
- an empty `convert_to_replicated` file in the table's data directory + restart
  (ClickHouse 24.2+; path via `SELECT data_paths FROM system.tables WHERE ...`), or
- create the replicated table alongside and
  `ALTER TABLE new ATTACH PARTITION ID '...' FROM old` per partition (hardlinks, free),
  then swap names.

Replication is not a backup. Run `BACKUP TABLE otel_logs TO S3(...)` to object storage —
it is the only thing that protects against a bad `ALTER` or `DROP`.

**This is still advice, not something the repo does.** Nothing schedules or performs a
backup, so as of 2026-08-30 there is no restore path for the deployed tables. The index
migration in R5 touches only index metadata and is re-runnable, which is why it was
acceptable to ship without one — but the gap is real and the next schema change may not be
so forgiving.

### R8 — Ingest correctness

- **Batching:** use the exporter's `sending_queue`. Do **not** add the external `batch`
  processor — it has known data-loss behaviour.
- **Crash durability:** batching alone does not survive a restart. Requires a persistent
  `sending_queue.storage` (the `file_storage` extension).
- **Status codes:** return `503` with `Retry-After` on overload, never `400`. OTel clients
  treat 4xx as permanent and drop the batch; 5xx as retryable. Correct status codes buy
  more effective availability than a second server.
- **Schema management:** run with `create_schema: false`. Note this suppresses
  schema-creation DDL but the exporter still issues `DESC TABLE` on startup for optional
  column detection — it is not literally "INSERT only", whatever the README says.
- **Transport:** OTLP/HTTP only. PHP is a poor gRPC server. Document this clearly, because
  many collectors default to gRPC on 4317 and users will otherwise assume Bilis is broken.

### R9 — Retention and capacity

`ttl_only_drop_parts = 1` with daily partitions makes expiry a partition drop rather than
a rewrite. Keep partitions daily.

Rough sizing: ~80–120 bytes stored per log line after ZSTD. `bytes/day ≈ rate × 86400 × 100`.
Publish this formula, not just a spec table — volume is the variable users actually have.

Spans are ~70 bytes compressed and arrive at roughly 10x log volume, so traces dominate
the disk budget: ~1,000 spans/s fits 30 days in about 200 GB. Past that, in order: object
storage tiering (online `ALTER`; `SET materialize_ttl_after_modify = 0` first), then a
column TTL on `Events.*`, then tail sampling. If parts climb under trace load, raise
`async_insert_busy_timeout_ms` before anything else.

`trace_summary` keeps 90 days against the spans' 30. That is deliberate — one row per
trace is cheap, and it keeps trend data after the detail is gone — and it creates a state
the UI must handle: a trace that is listed but cannot be opened. It has no partitions, so
its TTL is a row-level delete-merge rather than a partition drop; at one row per trace
that is the cost of the correctness R11 buys.

**A full disk turns ClickHouse read-only and takes the whole product down.** Per-project
ingest quotas and a disk alert at 70% will prevent more downtime than any amount of
replication.

### R10 — Span status and kind are the exporter's `String()` forms, not the proto's

`exporter_traces.go` writes `span.Kind().String()` and `spanStatus.Code().String()`, and
pdata returns:

| Proto enum              | Stored value  |
|-------------------------|---------------|
| `SPAN_KIND_UNSPECIFIED` | `Unspecified` |
| `SPAN_KIND_INTERNAL`    | `Internal`    |
| `SPAN_KIND_SERVER`      | `Server`      |
| `SPAN_KIND_CLIENT`      | `Client`      |
| `SPAN_KIND_PRODUCER`    | `Producer`    |
| `SPAN_KIND_CONSUMER`    | `Consumer`    |
| `STATUS_CODE_UNSET`     | `Unset`       |
| `STATUS_CODE_OK`        | `Ok`          |
| `STATUS_CODE_ERROR`     | `Error`       |

Bilis writes the same forms (`App\Services\Ingest\SpanSemantics`) so its rows are
indistinguishable from a stock exporter's, and because `trace_summary_mv` counts errors
with `countIf(StatusCode = 'Error')`. Writing `STATUS_CODE_ERROR` there would report zero
errors on every trace, forever, without failing anything.

The wire is messier than the table: protobuf carries these as enum integers, OTLP/JSON as
either the integer or the enum name depending on the sender's protojson settings. Both are
accepted at the mapper; only these literals are ever stored.

### R11 — `trace_summary` is an AggregatingMergeTree, and readers must re-aggregate

Four things, all of which fail **silently**.

**Write side.**

- **`AggregatingMergeTree`, never `ReplacingMergeTree`.** The MV fires once per insert
  block, so one trace's spans arriving across several blocks emit several rows. Replacing
  keeps only the last and corrupts `Start`/`End`.
- **`max(if(...))`, not `anyIf(...)`.** A block carrying no root span contributes `''`, and
  `any` may pick that empty string during a merge — the root operation vanishes at random.
  `max` always prefers the real value.
- **No `PARTITION BY`.** `Start` is an aggregate and a merge can lower it across midnight;
  ClickHouse will not move a row between partitions. Costs `ttl_only_drop_parts`, which is
  affordable at one row per trace.

**Read side, and just as load-bearing.** Rows collapse only when parts merge, so *every*
query re-aggregates, and every predicate on an aggregated column — **the time window
included** — goes in `HAVING`:

```sql
SELECT TraceId,
       max(RootName) AS RootName, max(RootService) AS RootService,
       min(Start) AS Started, max(End) AS Ended,
       sum(SpanCount) AS SpanCount, sum(ErrorCount) AS ErrorCount
FROM trace_summary
WHERE ProjectId IN {projectIds:Array(String)}
  AND (ProjectId, TraceId) IN (/* candidates from trace_index, R13 */)
GROUP BY ProjectId, TraceId
HAVING min(Start) >= {from:DateTime64(9)} AND min(Start) <= {to:DateTime64(9)}
ORDER BY Started DESC
```

`SimpleAggregateFunction` exists precisely so this works. Without the `GROUP BY` a trace
whose spans arrived in three batches is returned three times, each with a `SpanCount` of 1
and two of them with an empty root name — and only under the load that splits batches,
which is when nobody is watching. Anything computed from these columns (an error-only
filter, a duration bound, a cursor, **the window itself**) belongs in `HAVING`, not `WHERE`.
An earlier version put `Start >= {from} AND Start <= {to}` in `WHERE`, which is a
*row-level* test: a trace whose later spans landed in a second block has a second row with
a later `Start` and an empty root, and a window boundary that fell between the blocks passed
only that row and aggregated a fragment — reproduced on 26.9 as root name `''`, 2 of 4
spans, 2 s for a 31 s trace — which the client, replacing rows by id, then wrote over the
good row.

Do not alias an aggregate to its own column name (`sum(ErrorCount) AS ErrorCount`): the
alias shadows the column, so the `sum(ErrorCount)` in a `HAVING` resolves to
`sum(sum(ErrorCount))` and ClickHouse raises `ILLEGAL_AGGREGATION`. `TraceQuery` therefore
projects `TraceSpanCount` / `TraceErrorCount` / `TraceRootName` / `TraceRootService`
rather than the column names. This one is not silent — but it is invisible to any test
using `Http::fake`, because nothing executes the SQL. It shipped once for exactly that
reason.

**`End` is `max(Timestamp + toIntervalNanosecond(Duration))`, not `max(Timestamp)`.**
`Timestamp` is a span's *start*, so the naive form records the last span's start as the
trace's end and understates every trace's duration by however long its final span ran.
Measured on seeded data before the fix: a 4.29-second trace reported 168ms. This is
silent — the number is plausible, just wrong — and it is guarded by the duration
assertion in `TraceSummaryTest`.

Covered by `tests/Feature/ClickHouse/TraceSummaryTest.php`, which inserts one trace's
spans in three separate blocks. It is skipped unless a live ClickHouse is reachable —
`Http::fake` cannot reproduce a materialized view — and it is the only test that catches
any of the four.

### R12 — `Events.*` and `Links.*` are position-aligned parallel arrays

`Events.Timestamp[i]`, `Events.Name[i]` and `Events.Attributes[i]` describe one event.
Never reorder, filter or truncate one without the others: an event's name would silently
attach to another event's attributes. Build them in a single pass on the way in
(`OtlpTraceMapper`), and zip them by index up to the shortest on the way out
(`TraceQuery::events()`).

`Events.Attributes` and `Links.Attributes` are `Array(Map)`. The outer `[]` is correct for
a span with no events; it is each **element** that must serialize as `{}` rather than
`[]`, or JSONEachRow discards the row — after the async insert has already been acked.
`SpanWriter::normalise()` is the only implementation of that rule; everything writing
spans goes through it, tests included.

### R13 — The trace list is answered from `trace_index` candidates, aggregated on `trace_summary`

Two tables, one question, always in this order:

1. **Nominate** from `trace_index` (§2.4), keyed `(ProjectId, Hour, TraceId)`, so the read
   is pruned by the window's hours rather than the project's history. Measured on 26.9
   against 400k traces over 90 days: a one-hour window reads `Granules 1/93` of the index
   (`PrimaryKey Keys: ProjectId, Hour`, plus the daily partition key) where the same window
   on `trace_summary` read every granule.
2. **Decide and aggregate** on `trace_summary`, over *every* block the nominated traces
   have — `(ProjectId, TraceId) IN (…)` on its own key — with the window in `HAVING` on
   `min(Start)` (R11).

The index is a superset by construction: a trace whose true start is in the window has a
block whose start is in the window, filed under an hour in the window. The converse is not
true — a trace that began before the window and had spans start inside it is nominated
too — which is exactly why membership is decided on the summary's `min(Start)` and never
on an index row. `TraceQuery::list()` looks back six hours before `from` in the candidate
query so such a trace's real start is usually visible there and it never takes a candidate
slot; a page then costs at most `limit + 150` point lookups. When a filter can only be
judged on the aggregate (errors-only, minimum duration, root service) the candidate query
is not limited, and the read is bounded by the window's width instead.

**The tail nominates on `End`, not `Start`.** A poll asks "which traces changed since my
cursor", and a trace changes whenever a block of its spans lands. `End > {after}` catches
a root span that started minutes before the cursor and ended after it — a session, a queue
job — which `Start > {after}` never could, because the root's start is older than the
cursor by definition. What comes back is the whole aggregate, and the client's
replace-by-id merge is what makes re-sending it the right thing.

**The index is fed two ways and must agree with the summary.** `trace_index_mv` fires on
every insert alongside `trace_summary_mv`; `0009_backfill_trace_index.sql` covers
everything written before the index existed. The backfill re-runs on every boot and
decides for itself, cheaply: it runs while the index is empty or its `min(Hour)` is later
than the summary's earliest trace start (bounded to the summary's last 89 days, because
expiry is lazy on both tables and not in step — the index drops whole day partitions at
90–91 days, so in steady state its earliest hour sits below any summary trace in that band
and the guard stays false). Two column mins, never `uniqExact` over every trace pair: that
would hash the whole history into memory at every container start. It is **not** guarded on emptiness alone, because
during a
rolling deploy an old container keeps ingesting and feeds the new view before the backfill
statement runs — an "is it empty" test would then skip the whole history silently. A
spurious run only inserts rows that share their key with existing ones and merge away.
The backfill aliases its aggregates away from the column names (`min(Start) AS FirstStart`,
mapped by position) for the R11 alias reason: `min(Start) AS Start` is
`ILLEGAL_AGGREGATION`.

Covered live by `TraceSummaryTest` ("aggregates every block of a trace whose blocks
straddle the window boundary"), which writes one trace as two synchronous blocks thirty
seconds apart and asserts that a list window opening between them returns nothing, and a
tail cursor between them returns the trace whole.

---

## 4. Open decisions

- License: Apache 2.0 vs AGPL. Decide before external contributions arrive — relicensing
  later needs a CLA collected from day one.
- `JSON` column type instead of `Map` (per-exporter `json: true`). Tempting now that the
  floor is 26.2: it gives natively typed attributes and removes the `toFloat64OrNull`
  problem R1 leaves behind, which matters more for spans than for logs because numeric
  span attributes are the ones people filter on. The risk is `max_dynamic_paths` (default
  1024) against the union of every tenant's attribute keys, and switching later means
  rewriting every query against both tables. Benchmark before deciding; do not guess.
- Record the production ClickHouse version in §1, and pin a server version somewhere the
  repo can see — there is currently no image tag, compose file or CI service container
  anywhere, so nothing enforces the floor this schema requires.
- Backups (R7). Advised since day one, still unimplemented.
- Re-evaluate the five-minute bucket once real query profiles exist; switching costs
  one deploy plus 30 days of TTL overlap.
- Published self-host floor. Target ARM64 / 2 vCPU / 4 GB if it genuinely works — "runs on
  the cheapest box available" is positioning, not a footnote.
