---
title: Traces
description: Sending spans over OTLP/HTTP, why gRPC is not supported, per-SDK setup, one Collector config for both signals, and how traces link to logs.
order: 2
---

Bilis accepts distributed traces at one endpoint, in the same two encodings the
logs endpoint takes, with the same API key and the same never-blame-the-client
contract.

| Endpoint              | Payload                                                | Success |
| --------------------- | ------------------------------------------------------ | ------- |
| `POST /api/v1/traces` | OTLP `ExportTraceServiceRequest`, JSON **or** protobuf | `200`   |

One row is stored per span. Spans that cannot be parsed are skipped and counted
in an OTLP `partialSuccess` response; a storage failure answers `503` with
`Retry-After`. Bilis never returns a `4xx` for the contents of a payload — OTel
clients treat `4xx` as permanent and drop the batch. Bodies sent with
`Content-Encoding: gzip` or `deflate` are inflated, which is what the SDKs and
the Collector send by default.

## gRPC is not supported

**This is the thing that will look like an outage and is not one.** The
OpenTelemetry Collector and most SDKs default to OTLP over **gRPC on port
4317**. Bilis speaks OTLP over **HTTP only**. Point your exporter at the HTTP
protocol explicitly, and give it Bilis' `/api` prefix as the base:

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf   # or http/json
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20bilis_your_key_here
```

With `http/protobuf` or `http/json` the SDK appends the signal path to the
signal-agnostic endpoint itself — `/v1/traces` for spans, `/v1/logs` for logs —
which is why the value above ends in `/api` and not in `/api/v1/traces`. Set to
the bare host, an SDK would post to `/v1/traces`, which does not exist here.

The per-signal variable is used **verbatim** instead, so that one names the
full path:

```bash
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://your-bilis-host/api/v1/traces
```

Either form works; use the per-signal one when logs and traces go to different
places.

## Quickstart per SDK

Each of these is the auto-instrumentation route: no spans written by hand, the
SDK instruments your HTTP server, client and database driver. Set
`OTEL_SERVICE_NAME` — it colours the span's bar in the waterfall and is the
service filter in the trace list. Metrics are switched off in each example
because Bilis has nowhere to put them; an exporter left on would retry them
forever.

### Node

```bash
npm install @opentelemetry/api @opentelemetry/auto-instrumentations-node
```

```bash
OTEL_SERVICE_NAME=checkout
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=none
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_your_key_here"

node --require @opentelemetry/auto-instrumentations-node/register app.js
```

### Python

```bash
pip install opentelemetry-distro opentelemetry-exporter-otlp-proto-http
opentelemetry-bootstrap -a install
```

```bash
OTEL_SERVICE_NAME=checkout
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=none
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_your_key_here"

opentelemetry-instrument python app.py
```

The Python distro defaults to gRPC, so `OTEL_EXPORTER_OTLP_PROTOCOL` is not
optional here.

### Go

Go has no auto-instrumentation switch; the exporter is wired in code and the
libraries you use are wrapped (`otelhttp`, `otelsql`, and so on). The exporter
is protobuf-only, which Bilis decodes:

```go
exporter, err := otlptracehttp.New(ctx,
	otlptracehttp.WithEndpoint("your-bilis-host"),
	otlptracehttp.WithURLPath("/api/v1/traces"),
	otlptracehttp.WithHeaders(map[string]string{
		"Authorization": "Bearer " + os.Getenv("BILIS_API_KEY"),
	}),
	otlptracehttp.WithCompression(otlptracehttp.GzipCompression),
)
if err != nil {
	return err
}

provider := sdktrace.NewTracerProvider(
	sdktrace.WithBatcher(exporter),
	sdktrace.WithResource(resource.NewSchemaless(semconv.ServiceName("checkout"))),
)
otel.SetTracerProvider(provider)
defer provider.Shutdown(ctx)

http.ListenAndServe(":8080", otelhttp.NewHandler(mux, "server"))
```

The environment variables from the previous section work too —
`OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` replaces the `WithEndpoint` /
`WithURLPath` pair. The [Go](/docs/ingestion/go) guide covers logs from the
same service.

### PHP and Laravel

The OpenTelemetry PHP SDK auto-instruments Laravel through the `opentelemetry`
PHP extension plus one package:

```bash
pecl install opentelemetry
composer require open-telemetry/sdk open-telemetry/exporter-otlp \
    open-telemetry/opentelemetry-auto-laravel
```

```bash
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=checkout
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=none
OTEL_LOGS_EXPORTER=none
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf   # http/json if you skip the protobuf dependency
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_your_key_here"
```

Requests, queued jobs, console commands, Eloquent queries, cache calls and
outbound HTTP become spans without a code change. `OTEL_LOGS_EXPORTER=none`
because a Laravel app's log lines are better shipped through the
[Monolog channel](/docs/ingestion/shippers#laravel), which is where the
correlation between those lines and these spans is described.

## Collector configuration

If a Collector sits between your services and Bilis, one `otlphttp` exporter
carries both signals. This is the complete, runnable file — receivers,
extension and both pipelines — and it is the same one the
[Shippers](/docs/ingestion/shippers#opentelemetry-collector) and
[Go](/docs/ingestion/go) pages refer to:

```yaml
receivers:
    otlp:
        protocols:
            # Your services may still speak gRPC to the Collector; only the
            # hop to Bilis has to be HTTP.
            grpc: { endpoint: 0.0.0.0:4317 }
            http: { endpoint: 0.0.0.0:4318 }

extensions:
    file_storage:
        directory: /var/lib/otelcol/storage

exporters:
    otlphttp/bilis:
        logs_endpoint: https://your-bilis-host/api/v1/logs
        traces_endpoint: https://your-bilis-host/api/v1/traces
        headers:
            Authorization: Bearer bilis_your_key_here
        # gzip is the exporter's default; Bilis inflates gzip and deflate.
        compression: gzip
        # Protobuf is the default encoding and is decoded in-process;
        # `encoding: json` works too.
        sending_queue:
            enabled: true
            storage: file_storage # survives a Collector restart
        retry_on_failure:
            enabled: true

service:
    extensions: [file_storage]
    pipelines:
        logs:
            receivers: [otlp]
            processors: [] # deliberately no batch processor
            exporters: [otlphttp/bilis]
        traces:
            receivers: [otlp]
            processors: []
            exporters: [otlphttp/bilis]
```

Use the exporter's own `sending_queue` rather than the standalone `batch`
processor, and give the queue persistent storage if you care about surviving a
Collector restart. There is no `metrics` pipeline on purpose: a metrics pipeline
pointed at Bilis would fail every export. The reasoning behind each setting is
on the [Shippers](/docs/ingestion/shippers#opentelemetry-collector) page.

## Sending a span by hand

```bash
curl -X POST https://your-bilis-host/api/v1/traces \
  -H "Authorization: Bearer bilis_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{
    "resourceSpans": [{
      "resource": {"attributes": [
        {"key": "service.name", "value": {"stringValue": "checkout"}}
      ]},
      "scopeSpans": [{
        "spans": [{
          "traceId": "5b8efff798038103d269b633813fc60c",
          "spanId": "eee19b7ec3c1b174",
          "name": "POST /checkout",
          "kind": 2,
          "startTimeUnixNano": "1756550400000000000",
          "endTimeUnixNano": "1756550400250000000",
          "status": {"code": 2, "message": "checkout failed"}
        }]
      }]
    }]
  }'
```

`traceId` is 32 hex characters and `spanId` is 16. A span with no
`parentSpanId`, or one whose parent is all zeroes, is the trace's **root** — its
name and service are what the trace list shows, so a trace whose root never
arrives is listed without an operation name.

`kind` and `status.code` accept the proto enum number (as above) or its name
(`SPAN_KIND_SERVER`, `STATUS_CODE_ERROR`). Both are stored in the
OpenTelemetry Collector's own spelling: `Server`, `Error`.

## Traces and logs are linked both ways

The logs table already carries `TraceId` and `SpanId`. When your logger records
them — most OTel-instrumented loggers do automatically — Bilis joins the two
signals for you:

- a log line showing a trace id gets a link straight to that trace's waterfall,
- a span in the waterfall links back to the log viewer, filtered to that exact
  trace and span.

This is the main reason to keep both signals in one place, and it costs nothing
beyond having the ids on the log record. For the simple JSON endpoint that means
a top-level `trace_id` and `span_id`; the Laravel Monolog channel lifts them
out of the log context for you — see
[Shippers](/docs/ingestion/shippers#correlating-logs-with-traces). Exceptions
that arrive through the [Sentry-compatible endpoint](/docs/ingestion/sentry)
link the same way when the SDK put a trace context on the event.

## Retention

Spans are kept for **30 days**. Trace summaries — the row behind each entry in
the trace list, carrying the root operation, duration, span count and error
count — are kept for **90 days**.

So a trace can outlive its own detail: after 30 days it still appears in the
list with its shape intact, but its waterfall can no longer be drawn. That is
deliberate, and the interface says so rather than showing you an empty chart.
The `ALTER` statements that change either window are in
[Limits and behavior](/docs/reference/limits-and-behavior#changing-retention).

## Sizing

A span costs roughly **70 bytes** on disk after compression, and applications
typically emit far more spans than log lines — an order of magnitude is a
reasonable planning assumption. At 1,000 spans per second, 30 days of retention
is about 200 GB.

If that is too much, in order of what to try first: sample at the collector,
shorten the span TTL, or drop span events. A full disk turns ClickHouse
read-only and takes the whole install down, so watch it before it matters.
