---
title: Quickstart
description: From a fresh account to a log line in the viewer, in three steps.
order: 2
---

You need a project, an API key, and one HTTP request. Nothing else has to be
configured before the first line lands.

## 1. Create a project

A project is the unit logs are grouped and filtered by, and the unit an API key
belongs to. Create one from **Projects → New project** in the app. One project
per application or environment is the usual shape (`checkout-production`,
`checkout-staging`).

## 2. Create an API key

Open the project and create a key. Keys look like `bilis_…` and are shown once,
at creation:

```text
bilis_9EhVQ0k2mR7pYt3sX1nL4bC6dF8gJ0aZ
```

Only a SHA-256 hash of the key is stored, so a lost key cannot be recovered —
create a new one and delete the old.

## 3. Send a line

The simple JSON endpoint is the fastest proof that the pipe works:

```bash
curl -X POST https://bilis.example.com/api/v1/ingest \
  -H "Authorization: Bearer bilis_YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"message":"Hello from curl","level":"info","service":"checkout"}'
```

A successful call answers `202 Accepted`:

```json
{ "accepted": 1, "skipped": 0 }
```

Open the log viewer, pick the project, and the line is there. If it is not,
widen the time range first — a client-supplied `timestamp` puts the line where
_it_ says it belongs, not where it arrived. See
[Timestamps](/docs/ingestion/timestamps).

> **Note:** `202` means the batch was handed to ClickHouse's asynchronous insert
> buffer, not that it is on disk. That distinction is spelled out in
> [Limits and behavior](/docs/reference/limits-and-behavior).

## Then point a real shipper at it

- **Any OpenTelemetry SDK or the Collector** — three environment variables, no
  code change.
- **Laravel** — a Monolog channel that buffers records and ships them after the
  response.

Both are in [Shippers](/docs/ingestion/shippers). The in-app **Get started**
panel renders the same snippets pre-filled with this instance's hostname.
