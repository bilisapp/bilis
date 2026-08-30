---
title: Traces
description: Sending spans over OTLP/HTTP, why gRPC is not supported, and how traces link to logs.
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
clients treat `4xx` as permanent and drop the batch.

## gRPC is not supported

**This is the thing that will look like an outage and is not one.** The
OpenTelemetry Collector and most SDKs default to OTLP over **gRPC on port
4317**. Bilis speaks OTLP over **HTTP only**. Point your exporter at the HTTP
port and the HTTP protocol explicitly:

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20bilis_your_key_here
```

With `http/protobuf` the SDK appends `/v1/traces` itself. Bilis serves the
endpoint at `/api/v1/traces`, so set the traces endpoint explicitly if your SDK
does not let you set a base path:

```bash
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://your-bilis-host/api/v1/traces
```

## Collector configuration

```yaml
exporters:
    otlphttp/bilis:
        traces_endpoint: https://your-bilis-host/api/v1/traces
        headers:
            Authorization: Bearer bilis_your_key_here
        # The exporter gzips by default; Bilis inflates gzip and deflate.
        compression: gzip
        sending_queue:
            enabled: true
            storage: file_storage

service:
    pipelines:
        traces:
            receivers: [otlp]
            exporters: [otlphttp/bilis]
```

Use the exporter's own `sending_queue` rather than the standalone `batch`
processor, and give the queue persistent storage if you care about surviving a
collector restart. Both points apply exactly as they do for logs.

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
beyond having the ids on the log record.

## Retention

Spans are kept for **30 days**. Trace summaries — the row behind each entry in
the trace list, carrying the root operation, duration, span count and error
count — are kept for **90 days**.

So a trace can outlive its own detail: after 30 days it still appears in the
list with its shape intact, but its waterfall can no longer be drawn. That is
deliberate, and the interface says so rather than showing you an empty chart.

## Sizing

A span costs roughly **70 bytes** on disk after compression, and applications
typically emit far more spans than log lines — an order of magnitude is a
reasonable planning assumption. At 1,000 spans per second, 30 days of retention
is about 200 GB.

If that is too much, in order of what to try first: sample at the collector,
shorten the span TTL, or drop span events. A full disk turns ClickHouse
read-only and takes the whole install down, so watch it before it matters.
