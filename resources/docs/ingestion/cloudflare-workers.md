---
title: Cloudflare Workers
description: Send a Worker's logs, request spans and exceptions to Bilis — with Cloudflare's own OTLP export, a Sentry-compatible SDK, or both.
order: 12
---

A Worker has no host to install an agent on and no long-lived process to run an
OpenTelemetry SDK in, so the usual ways of shipping telemetry do not apply.
Two do, and they complement each other:

| Route                                         | What you get                                                    | Code change | Plan         |
| --------------------------------------------- | --------------------------------------------------------------- | ----------- | ------------ |
| [Cloudflare OTLP export](#cloudflare-otlp-export) | `console.*` output, the runtime's own logs, request spans   | none        | Workers Paid |
| [Sentry SDK](#sentry-sdk)                     | Exceptions with stack traces, breadcrumbs and request context   | a wrapper   | any          |

Use the export for the everyday picture and add the SDK when you want the
stack trace behind an error. The snippets on **Projects → your project →
Monitor errors** are filled in with this instance's address and the project's
DSN.

## Cloudflare OTLP export

Workers Observability can export traces and logs to any OTLP/HTTP endpoint. It
sends the JSON encoding, which Bilis reads natively. Metrics are not exported
by Cloudflare yet.

### 1. Add two destinations

In the Cloudflare dashboard, open **Workers & Pages → Observability →
Destinations** and add one destination per signal:

| Field         | Traces                                  | Logs                                    |
| ------------- | --------------------------------------- | --------------------------------------- |
| Name          | `bilis-traces`                          | `bilis-logs`                            |
| Type          | Traces                                  | Logs                                    |
| OTLP endpoint | `https://bilis.example.com/api/v1/traces` | `https://bilis.example.com/api/v1/logs` |
| Custom header | `Authorization` = `Bearer bilis_YOUR_API_KEY` | same                              |

The endpoint is the full URL, path included. The header carries a
[secret API key](/docs/ingestion/api-keys) — not the DSN's public half.

### 2. Point the Worker at them

```jsonc
// wrangler.jsonc
{
  "observability": {
    "traces": { "enabled": true, "destinations": ["bilis-traces"], "persist": false },
    "logs": { "enabled": true, "destinations": ["bilis-logs"], "persist": false }
  }
}
```

```toml
# wrangler.toml
[observability.traces]
enabled = true
destinations = ["bilis-traces"]
persist = false

[observability.logs]
enabled = true
destinations = ["bilis-logs"]
persist = false
```

Redeploy the Worker. Data takes a few minutes to arrive, and the destination's
status in the dashboard turns to **Last: n minutes ago** once Bilis has
accepted a batch. **Error** there almost always means a wrong endpoint path or
key.

`persist: false` keeps the data out of Cloudflare's own dashboard storage,
which is billed separately; drop it if you want both. `head_sampling_rate`
(0–1) is accepted on either block when a busy Worker produces more than you
want to keep.

### Cost and availability

The export is a Cloudflare feature, not a Bilis one. It needs the Workers Paid
plan, is in beta, and from **1 October 2026** it is billed by Cloudflare at
10 million included events a month per signal, then $0.05 per million. Check
[Cloudflare's page](https://developers.cloudflare.com/workers/observability/exporting-opentelemetry-data/)
for the current terms.

## Sentry SDK

`@sentry/cloudflare` wraps your handler, catches what it throws and posts it to
the DSN. Bilis accepts that protocol ([what is stored](/docs/ingestion/sentry)),
so the only change from Sentry's own setup is the DSN.

```bash
npm install @sentry/cloudflare
```

```jsonc
// wrangler.jsonc
{
  "compatibility_flags": ["nodejs_compat"],
  "vars": { "SENTRY_DSN": "https://bilis_pk_YOUR_PUBLIC_KEY@bilis.example.com/1" }
}
```

```ts
// src/index.ts
import * as Sentry from '@sentry/cloudflare';

export default Sentry.withSentry(
  (env: Env) => ({
    dsn: env.SENTRY_DSN,
    tracesSampleRate: 0,
    initialScope: { tags: { service: 'checkout-edge' } },
  }),
  {
    async fetch(request, env, ctx) {
      return new Response('Hello from the edge');
    },
  } satisfies ExportedHandler<Env>,
);
```

Three settings matter here:

- **`nodejs_compat`** is required by the SDK; it needs a `compatibility_date`
  of `2024-09-23` or later.
- **`tracesSampleRate: 0`**. Bilis stores the SDK's errors and drops its
  transactions, so sampling them only costs requests. Take traces from the
  OTLP export instead. (In a Worker the SDK's own spans also report `0ms`,
  because the runtime's clock only advances on I/O.)
- **The `service` tag.** An event carries no service of its own and a Worker
  reports no `server_name` to fall back on, so without the tag the error lands
  with an empty service.

The DSN is the public half of a key, so it can sit in `vars`; a secret works
just as well (`wrangler secret put SENTRY_DSN`). Durable Objects and Workflows
need their own wrapper — `instrumentDurableObjectWithSentry` and
`instrumentWorkflowWithSentry` — given the same options callback. If you build
with Vite, Sentry's Vite plugin does the wrapping for you; the DSN and the two
settings above are all that change.

To check the setup, throw from a route and look for an `ERROR` line in
**Logs**, filtered by the service you tagged:

```ts
if (new URL(request.url).pathname === '/debug-bilis') {
  throw new Error('Bilis test error');
}
```

## Both at once

The two routes do not share ids: the SDK starts its own trace, so an SDK error
does not link to the span Cloudflare exported for the same request. Search by
time and service to put them side by side — or, if the stack trace is not
essential, rely on the export alone, whose logs already include the runtime's
record of an uncaught exception.
