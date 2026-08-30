---
paths:
  - 'app/Services/Logs/**'
---

# Logs

## Log queries are always scoped to server-resolved project ids
LogQuery never accepts a project slug. The controller resolves the current team's projects, filters them by the requested slug, and passes an explicit list<string> of ids — ProjectId is a String column, so ids are cast at the controller boundary and bound as `{projectIds:Array(String)}`. An empty list short-circuits without touching ClickHouse. Every user value (service, severity bounds, search term, time window, cursor) is bound as a ClickHouse `{name:Type}` parameter — nothing is interpolated. A ClickHouseException with isOverload() returns `unavailable: true` with zero rows so the page still renders; any other ClickHouse error is rethrown.

## The query contract is SCHEMA.md R4 and R5
`database/clickhouse/SCHEMA.md` is the source of truth; these are the parts LogQuery has to honour.

**R4 — sort key `(ProjectId, Timestamp, ServiceName)`.** Range queries are a plain `ProjectId IN … AND Timestamp >= {from} AND Timestamp <= {to}` with `ORDER BY Timestamp DESC`. There is no bucket expression anywhere — do not add `toStartOfFiveMinutes` bounds. Live tail and "latest N" drop the range and read in order. Cursor pagination is a plain `Timestamp < {cursor}`, correct because Timestamp sits directly behind ProjectId in the key. The base predicate is built in exactly one method, `LogQuery::conditions()`; every other clause appends to what it returns and nothing may replace the ProjectId predicate. If the bucket is ever reintroduced, R4 requires constraining both the bucket expression and raw Timestamp, with the bound itself truncated.

**R5 — search, ClickHouse >= 26.2 branch.** A single `[A-Za-z0-9_]{3,}` token queries
`hasAnyTokens(lower(Body), [lower({search:String})])`, character for character the
expression `idx_lower_body` is defined on — a mismatch silently skips the index. Anything
else falls back to `lower(Body) LIKE lower({search:String})` with the wildcards escaped:
the slower substring "contains" mode, written against the indexed expression rather than
as `Body ILIKE` because ClickHouse can prune granules for a LIKE over a text index and
measurably does, while the ILIKE form reads none of it. The two fold case identically
(both ASCII only), so results are unchanged.

**The `lower()` wrapper stays on both sides.** `text(tokenizer = 'splitByNonAlpha')`
splits but does not fold case, so dropping it loses case-insensitive matching *and* the
index at once, silently. Prove index use with
`SETTINGS force_data_skipping_indices = 'idx_lower_body'`, which errors when the index is
not read. Index and query change together or not at all.

**Adding a trace filter, not replacing one.** `TraceId` / `SpanId` predicates from the
logs/traces link append to `conditions()` like any other filter; the ProjectId predicate
and the time window still apply, which is what keeps a span's log lookup bounded.

## Onboarding state comes from an existence query, never from the filter window
`LogOnboarding::state()` decides between `no-projects`, `no-logs` and `ready`. "Has this team ever logged?" is `LogQuery::hasAnyLogs()` — a `SELECT 1 ... LIMIT 1` over the team's full project id list, ignoring every filter, so an empty filtered window keeps the normal "no results" state instead of showing onboarding. It is deliberately unconstrained in time: the full scan is the point, and it stops at the first row.

An overloaded ClickHouse answers true: a hiccup must never make an established team look brand new. Only the positive answer is cached (`logs.onboarding.received.{team}`, 6h); false is re-checked every request so the logs page flips over as soon as the first line lands.

The onboarding prop is eager, not deferred — pass it the team's *whole* project id list, not the slug-filtered subset the search uses.
