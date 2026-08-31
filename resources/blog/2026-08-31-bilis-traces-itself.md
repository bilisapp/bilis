---
title: Pointing Bilis at itself found four bugs in an afternoon
description: Instrumenting Bilis with OpenTelemetry so it stores its own traces. The library choice was the easy part; the interesting part was everything that only breaks when a tracing backend is also a traced application.
date: 2026-08-31
author: Samuel Vrablik
---

Bilis stores traces. Bilis is a Laravel application. The obvious thing to do is
have it send its own spans to itself, and until this week it did not.

The setup took about twenty minutes. The rest of the afternoon went on four
problems that a normal Laravel app would never hit, because a normal Laravel app
is not also the thing receiving the spans. Two of them turned out to be bugs in
Bilis that had been shipped for weeks, and one of them briefly took the local
site down.

## Which library

There are two real options for Laravel, and the deciding question is whether you
can install a PECL extension.

The official `open-telemetry/opentelemetry-auto-laravel` requires
`ext-opentelemetry`. That is fine on a machine you control and unpleasant in a
container: it moves a compile step into the Dockerfile and turns a PHP upgrade
into a rebuild. It is the same objection that
[kept a protobuf library out of the ingest path](/blog/no-protobuf-library).

`keepsuit/laravel-opentelemetry` needs no extension. It instruments through
Laravel's own events and container, covers more ground (Livewire, views, console
commands, a real manual-span API), and — the part that actually decides it —
detects Octane, Horizon and queue workers, which is what determines whether
spans in a long-running process ever get flushed at all. Bilis deploys on
Octane/FrankenPHP. That is the one.

## It is opt-out, and Bilis is opt-in

The first thing the package did after `composer require` was print a stack trace.

Then another one. It ships enabled, with the exporter defaulting to
`localhost:4318` and the **metrics** exporter defaulting to `otlp`. Nothing was
listening on 4318, so the meter provider retried three times on every request and
shut down loudly. Metrics are explicitly out of scope for Bilis; there is nowhere
for them to go and never will be.

For an application people self-host, "enabled by default, pointed at a collector
you probably do not run" is the wrong shape. So it is inverted:

```php
'disabled' => filter_var(
    env(Variables::OTEL_SDK_DISABLED, env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT) === null),
    FILTER_VALIDATE_BOOLEAN
),
```

No endpoint, no SDK. That is the same contract the `bilis` log channel already
had — configured or inert, never half-on and complaining. Metrics and OTLP logs
both default to the `null` exporter: logs already leave through the Monolog
channel, and exporting them twice would store every line under two service
names.

## The loop you have to exclude

Here is the one that is specific to instrumenting a tracing backend.

The exporter sends spans by POSTing to `/api/v1/traces`. That POST is an inbound
HTTP request like any other, so the HTTP server instrumentation traces it. That
produces spans. Those spans are exported, by POSTing to `/api/v1/traces`.

It does not converge, and it does not need traffic to start — one request seeds
it. So the path the exporter writes to is excluded from the instrumentation that
would trace it:

```php
'excluded_paths' => [
    'api/v1/traces',   // recursion
    'api/v1/logs',     // volume
    'api/v1/ingest',
    '*/envelope',
    '*/store',
    'up',              // the healthcheck, forever, whether anyone is using this or not
],
```

Only the first line is about correctness. The rest is that the ingest routes are
the hot path of the product and would drown the trace list in copies of
themselves.

Worth knowing: excluding the path is sufficient, but only because the other
instrumentations check `Tracer::traceStarted()` before recording. Query and cache
instrumentation attach to an existing trace rather than starting one. If they
started their own root spans, excluding the HTTP path would not have been enough,
because authenticating the API key on that request runs a query.

## Bug one: the header that is url-encoded except when it is not

Configured, pointed at the local instance, and every export came back `401`.

`OTEL_EXPORTER_OTLP_HEADERS` is specified as url-encoded, which is why every
document on earth writes the bearer token as `Authorization=Bearer%20token`. The
Collector decodes it. The Go SDK decodes it. The PHP SDK's `MapParser` splits on
`,` and `=`, trims, and returns — no `urldecode` anywhere. So it sends the
literal string `Bearer%20bilis_...`, which is not a bearer token, and the failure
presents as a wrong key.

Bilis' own docs were telling people to write it that way.

The fix is not to document the exception. It is to stop needing an encoded
character: Bilis accepts the key in `X-Bilis-Key` as well as `Authorization`, and
that value contains no space.

```bash
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here
```

Correct in every SDK, with or without url-decoding, quoted or bare.

## Bug two: answering protobuf with JSON

Authorized, and now every export logged `Error occurred during parsing:
Unexpected wire type.`

The spans were in ClickHouse. The insert was fine. What was wrong was the
*response*: OTLP/HTTP is symmetric, and a protobuf export must be answered with a
protobuf `ExportTraceServiceResponse`. Bilis answered every export in JSON
whatever arrived. Clients were parsing `{}` as a protobuf message and reporting a
wire-format error after every successful batch.

This had been shipped for weeks and nothing caught it, because it is invisible
from the server's side. Bilis stored the data, returned 200, and logged nothing.
The complaint only exists in the client's log — so you only find it if you are
also the client.

Fixing it needed an encoder, which sounds worse than it is. The response schema
is two fields wide and the field *numbers* are the same for both signals:

```
ExportTraceServiceResponse { partial_success = 1 }
ExportTracePartialSuccess  { rejected_spans = 1 (int64), error_message = 2 (string) }
```

A hundred lines, hand-written, matching the decoder that was already there. The
detail worth keeping: a complete success is **zero bytes**. Proto3 omits fields
holding their default, so a response with no partial success is the empty
message. That is the wire form, not a shortcut — it is what the Collector sends.

## Bug three, sort of: the 502 I caused

Then the local site started returning 502, and the nginx log was full of refused
connections to the PHP socket.

Nothing had crashed. The worker pool had deadlocked, and the reason is worth
stating plainly, because it applies to anyone exporting telemetry from PHP to
something on the same host:

> The export runs synchronously at the end of a request, and it posts to an
> endpoint served by the same worker pool. So a request cannot release its worker
> until a *second* worker has answered the export. Every traced request occupies
> two.

Add a trace list polling every five seconds, a small FPM pool, and one slow
export, and the workers spend their time waiting on each other. The package
defaults — 10 second timeout, 3 retries — mean a single failing sink can hold a
worker for thirty seconds. The symptom is a 502 on an application that has no
bug in it.

The defaults are now three seconds and one retry. That is the same judgement
`BilisHandler` already makes for logs, where the timeout is two seconds and a
batch that cannot be delivered is dropped rather than queued behind the request.
Telemetry is never worth a user's latency. If you can put a Collector in front,
do — then the hop that has to be fast is a local one.

## Bug four: one application, two services

Everything worked. The viewer showed two services: `Bilis` with the logs, and
`bilis` with the spans, filtered separately, joined by nothing.

The log channel fell back to `APP_NAME`; the SDK used `OTEL_SERVICE_NAME`. One
line in `config/logging.php` — `'service' => env('OTEL_SERVICE_NAME')` — and both
signals name the same thing, with the app-name fallback intact for an install
that ships logs and no traces.

## The part that was already right

Linking a log line to its span needed nothing new, because the shipper was
already built for it: `BilisHandler` lifts `trace_id` and `span_id` out of
Monolog's `extra` onto the top-level fields the ingest endpoint reads.

The package can inject a trace id into the log context, but it shares only the
trace id, and via `Log::shareContext()`, which is global and outlives the span.
The Bilis docs already described the better version — a Monolog processor
stamping both ids from the span current when the line was written, guarded on
`isValid()` so that lines written outside a span get nothing rather than an
all-zero id that would join to every other such line.

That class existed only in the documentation. It is now
`App\Logging\AddTraceContext`, tapped onto the `bilis` channel, and the docs and
the application share one implementation.

## One more trap

`ConsoleInstrumentation` is enabled by default and its `commands` list is empty.
The list is a **whitelist**. Enabled with an empty list traces nothing, which
reads exactly like broken instrumentation. Bilis names the commands where a
regression shows up as a schedule quietly failing to keep up rather than as an
error: `autofix:scan`, `autofix:verify`, and the two ClickHouse commands.

## What this was actually worth

The library choice took ten minutes and was never really in doubt. Everything
after it came from the same place: Bilis had been receiving telemetry correctly
and answering it incorrectly, and no amount of testing the receiving side would
have shown that, because the receiving side was fine.

Two bugs in the response path, one dangerous default, and a documentation error
that would have cost every PHP user an hour on a `401` — all found by being the
first user of the thing.
