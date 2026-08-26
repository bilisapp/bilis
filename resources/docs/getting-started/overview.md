---
title: Overview
description: What Bilis is, what v1 does, and what it deliberately does not do.
order: 1
---

Bilis is self-hosted log storage and search. You point your applications at one
HTTP endpoint, the lines land in ClickHouse, and a viewer built for finding one
line among millions sits on top. One Laravel app and one database — no Grafana
stack to operate.

## What v1 does

- **One OTLP/HTTP ingest endpoint** — `POST /api/v1/logs`, JSON encoding — plus a
  simple JSON fallback at `POST /api/v1/ingest` for anything without an OTel
  exporter. See [Endpoints](/docs/ingestion/endpoints).
- **An OTel-compatible ClickHouse table.** The column names and types are the
  ones the upstream OpenTelemetry ClickHouse exporter writes, so the storage
  layer is not a private format you would have to migrate out of.
- **A log viewer**: time range, project / service / severity filters, full-text
  search over the message body, and live tail.
- **Projects and API keys.** A key belongs to exactly one project, and the
  project a log line lands in is decided by the key that carried it — never by
  anything in the payload.

## What is not in v1

Said plainly, so you can plan around it:

- No traces and no metrics. Logs only.
- No alerting, no dashboards, no saved searches.
- No eBPF collection, no S3 tiering, no ClickHouse replication.
- No billing.
- No OTLP over gRPC, and no OTLP protobuf encoding — JSON over HTTP only. See
  [Shippers](/docs/ingestion/shippers) for why.

> **Note:** these are scope decisions, not a roadmap countdown. If a feature
> above is load-bearing for you today, Bilis v1 is not the right tool yet.

## How a log line travels

1. Your app or collector POSTs a batch to `/api/v1/logs` or `/api/v1/ingest`
   with an API key.
2. The key resolves to a project. Bad records inside the batch are skipped and
   counted; the healthy ones still go through.
3. Rows are written to ClickHouse with `async_insert=1` and
   `wait_for_async_insert=0` — the response means *queued*, not *durable*. See
   [Limits and behavior](/docs/reference/limits-and-behavior).
4. The viewer reads them back, filtered by project and time range.

## Next

- [Quickstart](/docs/getting-started/quickstart) — project, key, first line.
- [Endpoints](/docs/ingestion/endpoints) — the request and response contract.
