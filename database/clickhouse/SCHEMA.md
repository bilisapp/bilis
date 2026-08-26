# Bilis — logs schema and rules

Status: v1, logs only. ClickHouse + Laravel (Octane/FrankenPHP), Traefik via Coolify, single OVH box.

---

## 1. Pinned reference

**Pin an exact collector version tag and record it here. Diff against that tag, never `main`.**

```
otel-collector-contrib: v0.159.0
schema source: exporter/clickhouseexporter/internal/sqltemplates/
                 (logs_table.sql, logs_insert.sql) — path confirmed present at that tag
clickhouse version: 25.x floor targeted; local dev server reports 26.9.1.158
last verified: 2026-08-26
```

The shipped DDL uses the **< 26.2** branch of R5 (`tokenbf_v1` on `lower(Body)`), because
that is the floor we support. A 26.9 server still accepts it — `tokenbf_v1` is deprecated
for full-text search there, not removed — so a newer dev box is not a reason to switch.
Raising the floor to >= 26.2 means changing the index *and* the query together.

The exporter schema is actively evolving. Every claim below is only true against a
specific tag. Re-diff on every collector upgrade and update the date above.

---

## 2. Table

```sql
CREATE TABLE otel_logs
(
    Timestamp          DateTime64(9)                       CODEC(Delta(8), ZSTD(1)),
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

    -- ClickHouse < 26.2 only. See rule 5 for >= 26.2.
    INDEX idx_lower_body lower(Body) TYPE tokenbf_v1(32768, 3, 0) GRANULARITY 8
)
-- Deliberate divergence from upstream. Upstream leads with
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

### R5 — Text search is version-dependent

- **ClickHouse < 26.2:** index `lower(Body)` with `tokenbf_v1`; query
  `hasToken(lower(Body), lower({q:String}))`. Index expression and query expression must
  match exactly.
- **ClickHouse >= 26.2:** `tokenbf_v1` is deprecated for full-text search. Use a `text`
  index on `Body`, **drop the `lower()` wrapper** (the tokenizer normalises), and prefer
  `hasAnyTokens` / `hasAllTokens`. `=`, `IN` and `LIKE` use a text index by default.

Do not carry the `lower()` wrapper across an upgrade — it becomes wrong, not merely
redundant.

`hasToken` is exact, case-sensitive, whole-token matching. It is not substring search.
`LIKE` *can* use `tokenbf_v1` when the pattern contains complete tokens; offer it as a
slower "contains" mode rather than claiming it is unaccelerated.

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

Replication is not a backup. Run `BACKUP TABLE otel_logs TO S3(...)` to object storage
from day one — it is the only thing that protects against a bad `ALTER` or `DROP`.

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

**A full disk turns ClickHouse read-only and takes the whole product down.** Per-project
ingest quotas and a disk alert at 70% will prevent more downtime than any amount of
replication.

---

## 4. Open decisions

- License: Apache 2.0 vs AGPL. Decide before external contributions arrive — relicensing
  later needs a CLA collected from day one.
- `JSON` column type instead of `Map` (per-exporter `json: true`; ClickHouse v25+
  recommended). Not for v1; possible opt-in later.
- Re-evaluate the five-minute bucket once real query profiles exist; switching costs
  one deploy plus 30 days of TTL overlap.
- Published self-host floor. Target ARM64 / 2 vCPU / 4 GB if it genuinely works — "runs on
  the cheapest box available" is positioning, not a footnote.
