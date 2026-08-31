---
title: Limits and behavior
description: What an acknowledgement means, how long logs and spans live, how to change that, how much disk they need, and the knobs a self-hosted install has.
order: 1
---

The honest list of what Bilis promises and what it does not.

## An acknowledgement is not durability

Rows are inserted with ClickHouse's asynchronous insert path:

```text
async_insert=1
wait_for_async_insert=0
```

The server acknowledges as soon as the batch is in its insert buffer, and
flushes to disk shortly after. So:

- **`202` / `200` means queued, not stored.** A ClickHouse crash in the window
  between the acknowledgement and the flush loses that buffer.
- The trade is throughput: small frequent inserts are what a log pipeline
  produces, and they are exactly what ClickHouse handles badly without this.
- Retries are safe. `non_replicated_deduplication_window = 1000` makes an
  identical re-sent batch idempotent, so a collector retrying after a timeout
  does not double your log lines.

If a line matters more than that, keep a local copy too.

## Retention

Logs are dropped after **30 days** by default, by a table TTL on the event
timestamp. Expiry is a partition drop rather than a rewrite
(`ttl_only_drop_parts = 1` with daily partitions), so it costs almost nothing.

**Spans** are dropped after **30 days** on the same terms. **Trace summaries** —
one row per trace carrying its root operation, duration, span count and error
count — are kept for **90 days**, so a trace outlives its own detail: after 30
days it still appears in the trace list, but its waterfall can no longer be
drawn. The interface says so rather than showing an empty chart.

Retention is a property of the ClickHouse tables, not a per-project setting.
Changing it means altering the TTL on `otel_logs`, `otel_traces` or
`trace_summary` — see [Changing retention](#changing-retention) below.

## Sizing

Roughly **80–120 bytes per log line** on disk after ZSTD compression. The
formula worth remembering:

```text
bytes/day ≈ lines/second × 86400 × 100
```

Which gives, at 30-day retention:

| Lines/second | Per day | 30 days |
|--------------|---------|---------|
| 10           | ~86 MB  | ~2.6 GB |
| 100          | ~864 MB | ~26 GB  |
| 1,000        | ~8.6 GB | ~260 GB |
| 10,000       | ~86 GB  | ~2.6 TB |

**Spans** cost roughly **70 bytes** each after compression, and an instrumented
application emits far more spans than log lines — ten times as many is a
reasonable planning assumption, so traces tend to dominate the disk budget. At
30-day span retention:

| Spans/second | Per day | 30 days |
|--------------|---------|---------|
| 100          | ~600 MB | ~18 GB  |
| 1,000        | ~6 GB   | ~180 GB |
| 10,000       | ~60 GB  | ~1.8 TB |

The 90-day `trace_summary` is one short row per trace and does not move these
numbers. If spans are the problem, in order of what to try first: sample at the
Collector, shorten the span TTL, or drop span events.

Volume is the variable you actually control, so measure your own rate rather
than trusting a tier label.

### Changing retention

Retention is a TTL on each table, and a TTL is changed with an online `ALTER`
against ClickHouse directly. Set `materialize_ttl_after_modify = 0` first: with
it, the `ALTER` is a metadata change and existing parts pick up the new TTL as
they are next merged; without it, ClickHouse rewrites every existing part
immediately, which on a large table is hours of I/O for no benefit.

```sql
SET
materialize_ttl_after_modify = 0;

ALTER TABLE otel_logs MODIFY TTL toDateTime(Timestamp) + toIntervalDay(30);
ALTER TABLE otel_traces MODIFY TTL toDateTime(Timestamp) + toIntervalDay(30);
ALTER TABLE trace_summary MODIFY TTL toDateTime(Start) + toIntervalDay(90);
```

The three values shown are the defaults; change the day counts. Keep the
expression as it is — `otel_logs` and `otel_traces` expire by the event
timestamp, `trace_summary` by the trace's start.

Two things worth knowing:

- `otel_logs` and `otel_traces` are partitioned by day and expire with
  `ttl_only_drop_parts = 1`, so shortening a window drops whole partitions at
  the next TTL merge and costs almost nothing. `trace_summary` has no
  partitions — it cannot, because a trace's start is an aggregate that a merge
  can move across midnight — so its TTL is a row-level delete-merge. It is one
  row per trace, so that is affordable, but it is not free the way the other
  two are.
- `php artisan clickhouse:migrate` creates tables it does not find and never
  rewrites one it does, so an `ALTER` you make survives every deploy. A fresh
  install, though, gets the defaults from `database/clickhouse/*.sql`; keep the
  statement in your own runbook.

> **Note:** a full disk turns ClickHouse read-only and takes the whole product
> down with it. Alert on disk at 70% — that will prevent more downtime than any
> amount of replication.

## Rate limits

Ingest is throttled per API key, so one runaway client cannot starve the
others:

| Requests                            | Limit                     | Counted per |
|-------------------------------------|---------------------------|-------------|
| With a valid `Authorization` header | **1,200 requests/minute** | API key     |
| Without one                         | **60 requests/minute**    | client IP   |

Two things worth being precise about:

- **The limit counts HTTP requests, not log lines.** A batch of 500 lines in
  one POST is one request. If you are anywhere near the limit, the fix is
  almost always batching — every OTLP exporter and the Bilis shipper batch by
  default, so hitting it usually means something is misconfigured to send one
  line per request.
- **A rejection is a `429` with `Retry-After`, never silent.** OTLP exporters
  treat it as retryable and back off, so a brief burst over the limit delays
  delivery rather than losing it. A client that ignores `Retry-After` and keeps
  hammering will lose whatever it fails to retry — that is the client's bug,
  not a Bilis behavior.

Self-hosted installs can change both knobs, `0` disables a limit entirely:

```bash
BILIS_INGEST_RATE_LIMIT=1200
BILIS_INGEST_RATE_LIMIT_UNAUTHENTICATED=60
```

The rate limit shapes _request rate_; it does not cap _stored volume_. A
well-batched client can fill the disk without ever seeing a `429` — see the
sizing table above, and the quota note below.

## Volume control belongs to the sender

Bilis stores what arrives. There is no server-side sampling, no
ingest-side downsampling, and none is planned: dropping data you deliberately
sent would be a surprising way to protect a disk you own.

That makes volume control your job, and the right place for it is _before_ the
log leaves your infrastructure — in the SDK or collector, where the decision is
cheap and nothing is transmitted just to be thrown away:

- **Don't ship what you won't read.** Raising the minimum severity for shipping
  (while keeping debug in local files) is the single highest-leverage knob.
- **Filter in the collector.** The OpenTelemetry Collector's `filter` processor
  drops records by severity, body match or attribute before export.
- **Sample the repetitive stuff.** Health-check hits and other high-frequency,
  low-information lines can be probabilistically sampled at the collector; keep
  errors at 100%.

If a collector-side rule sampled your logs, the counts you see in Bilis are
counts of what was shipped — Bilis does not extrapolate.

- **One node.** Plain `MergeTree`, no replication. Replication needs Keeper, and
  a replicated table with unreachable Keeper goes read-only — on a single box
  that adds a failure mode without adding redundancy.
- **Replication is not a backup either.** Back the table up to object storage
  from day one; it is the only thing that protects against a bad `ALTER`.
- **Full-text search is token-based** over the log body, backed by a text index.
  It matches whole tokens, case-insensitively. A search term that is not a
  single token falls back to a slower substring "contains" match, which still
  reads the index but prunes less.
- **No per-project ingest quota yet.** The per-key rate limit shapes request
  rate, but nothing caps how much a well-batched project can store — a noisy
  project can still fill the disk.

## Self-hosting

Bilis talks to ClickHouse over its HTTP interface, so there is no driver or PHP
extension to install:

**ClickHouse 26.2 or newer is required.** The log body index is a `text` index,
which older servers do not have. Check with `SELECT version()` before upgrading
Bilis; on an older server, body search would silently stop using its index.

```bash
CLICKHOUSE_SCHEME=http
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=bilis
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_TIMEOUT=10
CLICKHOUSE_CONNECT_TIMEOUT=3
```

Timeouts are deliberately short so an overloaded cluster fails fast and ingest
returns a retryable `503` instead of holding a PHP worker.

Create or update the tables with the idempotent migration command:

```bash
php artisan clickhouse:migrate
```

It applies `database/clickhouse/*.sql` in filename order. That directory, and
`database/clickhouse/SCHEMA.md` alongside it, are the source of truth for the
`otel_logs`, `otel_traces` and `trace_summary` tables — the column names and
types of the first two are the upstream OpenTelemetry exporter's, which is what
keeps the storage readable by tools other than Bilis.

### Upgrading an install that predates the text body index

If you have been running Bilis since before the ClickHouse 26.2 floor, your
`otel_logs` still carries the old token bloom filter index. `clickhouse:migrate`
swaps the index definition for you — instantly, and without interrupting search.
Rows written before the swap keep answering body searches by full scan until you
rebuild their index files:

```bash
php artisan clickhouse:materialize-index
```

Run it once, deliberately, when the box has I/O to spare. It is deliberately not
part of `clickhouse:migrate`, which runs on every container start.
