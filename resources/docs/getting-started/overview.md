---
title: Overview
description: What Bilis is, what v1 does, and what it deliberately does not do.
order: 1
---

Bilis is self-hosted log and trace storage and search. You point your
applications at one host, the lines and the spans land in ClickHouse, and a
viewer built for finding one line among millions — and the request it belonged
to — sits on top. One Laravel app and one database — no Grafana stack to
operate.

## What v1 does

- **OTLP/HTTP ingest for logs and traces** — `POST /api/v1/logs` and
  `POST /api/v1/traces`, each accepting the JSON **and** the protobuf encoding,
  gzip or deflate compressed or not — plus a simple JSON fallback at
  `POST /api/v1/ingest` for anything without an OTel exporter. See
  [Endpoints](/docs/ingestion/endpoints) and [Traces](/docs/ingestion/traces).
- **OTel-compatible ClickHouse tables.** The column names and types of
  `otel_logs` and `otel_traces` are the ones the upstream OpenTelemetry
  ClickHouse exporter writes, so the storage layer is not a private format you
  would have to migrate out of.
- **A log viewer**: time range, project / service / severity filters, full-text
  search over the message body, and live tail.
- **A trace viewer**: a trace list with live tail, a span waterfall per trace,
  and latency per service.
- **Logs and traces linked both ways.** A log line carrying a trace id opens
  its trace's waterfall; a span in the waterfall opens the log viewer filtered
  to that exact trace and span.
- **A Sentry-SDK-compatible endpoint** for shipping exceptions your app already
  catches, stored as error logs. See
  [Sentry-compatible ingest](/docs/ingestion/sentry).
- **Projects and API keys.** A key belongs to exactly one project, and the
  project a log line or span lands in is decided by the key that carried it —
  never by anything in the payload.

## What is not in v1

Said plainly, so you can plan around it:

- No metrics. Logs and traces only.
- No alerting, no user-defined dashboards, no saved searches.
- No eBPF collection, no S3 tiering, no ClickHouse replication.
- No billing.
- No OTLP over **gRPC** — HTTP only, in either encoding. Collectors and SDKs
  default to gRPC on port 4317, which is the most common reason a new install
  looks broken. See [Traces](/docs/ingestion/traces#grpc-is-not-supported).

> **Note:** these are scope decisions, not a roadmap countdown. If a feature
> above is load-bearing for you today, Bilis v1 is not the right tool yet.

## How a log line travels

1. Your app or collector POSTs a batch to `/api/v1/logs` or `/api/v1/ingest`
   with an API key.
2. The key resolves to a project. Bad records inside the batch are skipped and
   counted; the healthy ones still go through.
3. Rows are written to `otel_logs` with `async_insert=1` and
   `wait_for_async_insert=0` — the response means _queued_, not _durable_. See
   [Limits and behavior](/docs/reference/limits-and-behavior).
4. The viewer reads them back, filtered by project and time range.

## How a span travels

1. Your SDK or collector POSTs an OTLP export to `/api/v1/traces` with the same
   API key. Spans that cannot be parsed are skipped and reported in the OTLP
   `partialSuccess` response; the rest go through.
2. One row per span is written to `otel_traces`, on the same asynchronous
   insert path as the logs.
3. A materialized view folds each insert into `trace_summary` — one row per
   trace carrying its root operation, start and end, span count and error
   count. That is what the trace list reads, and it outlives the spans
   themselves: spans are kept for 30 days, summaries for 90.
4. The waterfall reads the spans back by trace id inside a time window; a log
   line whose `TraceId` matches links to it, and a span links back to the logs
   that were written under it.

## Next

- [Quickstart](/docs/getting-started/quickstart) — project, key, first line.
- [Endpoints](/docs/ingestion/endpoints) — the request and response contract.
- [Traces](/docs/ingestion/traces) — the span endpoint, per-SDK setup, and the
  Collector configuration for both signals.
