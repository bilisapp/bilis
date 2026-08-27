---
title: Limits and behavior
description: What an acknowledgement means, how long logs live, how much disk they need, and the knobs a self-hosted install has.
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

Retention is a property of the ClickHouse table, not a per-project setting in
v1. Changing it means altering the TTL on `otel_logs`.

## Sizing

Roughly **80–120 bytes per log line** on disk after ZSTD compression. The
formula worth remembering:

```text
bytes/day ≈ lines/second × 86400 × 100
```

Which gives, at 30-day retention:

| Lines/second | Per day | 30 days |
| ------------ | ------- | ------- |
| 10           | ~86 MB  | ~2.6 GB |
| 100          | ~864 MB | ~26 GB  |
| 1,000        | ~8.6 GB | ~260 GB |
| 10,000       | ~86 GB  | ~2.6 TB |

Volume is the variable you actually control, so measure your own rate rather
than trusting a tier label.

> **Note:** a full disk turns ClickHouse read-only and takes the whole product
> down with it. Alert on disk at 70% — that will prevent more downtime than any
> amount of replication.

## Rate limits

Ingest is throttled per API key, so one runaway client cannot starve the
others:

| Requests | Limit | Counted per |
| --- | --- | --- |
| With a valid `Authorization` header | **1,200 requests/minute** | API key |
| Without one | **60 requests/minute** | client IP |

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

The rate limit shapes *request rate*; it does not cap *stored volume*. A
well-batched client can fill the disk without ever seeing a `429` — see the
sizing table above, and the quota note below.

## Volume control belongs to the sender

Bilis stores what arrives. There is no server-side sampling, no
ingest-side downsampling, and none is planned: dropping data you deliberately
sent would be a surprising way to protect a disk you own.

That makes volume control your job, and the right place for it is *before* the
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
- **Full-text search is token-based** over the log body, backed by a token bloom
  filter index. It matches whole tokens, case-insensitively — not substrings.
- **No per-project ingest quota yet.** The per-key rate limit shapes request
  rate, but nothing caps how much a well-batched project can store — a noisy
  project can still fill the disk.

## Self-hosting

Bilis talks to ClickHouse over its HTTP interface, so there is no driver or PHP
extension to install:

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

Create or update the log table with the idempotent migration command:

```bash
php artisan clickhouse:migrate
```

It applies `database/clickhouse/*.sql` in filename order. That directory, and
`database/clickhouse/SCHEMA.md` alongside it, are the source of truth for the
`otel_logs` table — the column names and types are the upstream OpenTelemetry
exporter's, which is what keeps the storage readable by tools other than Bilis.
