---
title: Shippers
description: curl, OpenTelemetry exporters, a Laravel Monolog channel, and Collector configuration that does not lose data.
order: 4
---

Anything that can POST JSON can ship to Bilis. These are the four paths that
are actually used.

## curl

The one-liner that proves the pipe works:

```bash
curl -X POST https://bilis.example.com/api/v1/ingest \
  -H "Authorization: Bearer bilis_YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"message":"Hello from curl","level":"info","service":"checkout"}'
```

## OpenTelemetry SDKs

Any OTLP exporter — Go, Python, Node, Java, .NET, the Collector — needs no code
change, only configuration. Two things must be right: the **JSON** encoding, and
an endpoint that resolves to `/api/v1/logs`.

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.example.com/api/v1/logs
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_YOUR_API_KEY"
```

`OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` is used **verbatim**, which is why it names
the full path. The signal-agnostic variable behaves differently — exporters
append `/v1/logs` to it — so if you prefer that one, give it the base only:

```bash
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_ENDPOINT=https://bilis.example.com/api
```

Set `OTEL_SERVICE_NAME` too — it becomes the service filter in the viewer.

> **Note:** OTLP over **gRPC is not supported**, and neither is the protobuf
> encoding over HTTP. Bilis is a PHP application, and PHP is a poor gRPC server;
> protobuf would mean a new dependency for no gain over JSON at these volumes.
> Many collectors and SDKs default to gRPC on port 4317, so an unconfigured
> exporter will look like Bilis is down. A protobuf request over HTTP answers
> `415` with the exact variable to change.

## Laravel

A first-party package is on the way. Until then, a custom Monolog channel takes
about a minute to wire up.

```bash
# .env
BILIS_ENDPOINT=https://bilis.example.com
BILIS_API_KEY=bilis_YOUR_API_KEY
LOG_STACK=single,bilis
```

```php
// config/logging.php — add the channel
'bilis' => [
    'driver' => 'custom',
    'via' => App\Logging\BilisLogger::class,
    // BILIS_ENDPOINT is the Bilis origin; the handler appends /api/v1/ingest.
    'endpoint' => env('BILIS_ENDPOINT'),
    'api_key' => env('BILIS_API_KEY'),
    'level' => env('BILIS_LOG_LEVEL', 'debug'),
],
```

Then drop the two classes — `BilisLogger` and `BilisHandler` — into
`app/Logging/`. The **Get started** panel inside the app renders both files in
full, copyable, straight from the source this instance is running, so they
cannot drift from what the endpoint actually accepts.

How the channel behaves, and why it is safe to leave in a stack:

- Records **buffer in memory** and ship as one batched request after the
  response, on `terminating()` — request latency is untouched.
- `BILIS_ENDPOINT` is just the Bilis origin, such as
  `https://bilis.example.com`; the handler chooses `/api/v1/ingest` itself.
- With `BILIS_ENDPOINT` or `BILIS_API_KEY` unset the channel is **inert**, so it
  is harmless in a shared `config/logging.php` across environments.
- A dead or unreachable Bilis never breaks your application. Failures to ship
  are swallowed, not thrown.

Keep a local channel in the stack (`single,bilis`). A remote log target is not a
place to put your only copy of the logs.

## OpenTelemetry Collector

If you already run a Collector, point its OTLP HTTP exporter at Bilis. Four
settings decide whether you lose data under load:

```yaml
extensions:
    file_storage:
        directory: /var/lib/otelcol/storage

exporters:
    otlphttp/bilis:
        logs_endpoint: https://bilis.example.com/api/v1/logs
        encoding: json
        headers:
            Authorization: Bearer bilis_YOUR_API_KEY
        sending_queue:
            enabled: true
            storage: file_storage # survives a restart
        retry_on_failure:
            enabled: true

service:
    extensions: [file_storage]
    pipelines:
        logs:
            receivers: [otlp]
            processors: [] # deliberately no batch processor
            exporters: [otlphttp/bilis]
```

- **Batch with the exporter's `sending_queue`, not the `batch` processor.** The
  external batch processor has known data-loss behaviour on shutdown.
- **In-memory batching does not survive a restart.** Durability needs a
  persistent `sending_queue.storage`, which is what the `file_storage`
  extension provides.
- **Retries work because Bilis returns the right codes.** Overload and storage
  failures come back as `503` with `Retry-After`, never `400`. See
  [Endpoints](/docs/ingestion/endpoints).
- If you use the ClickHouse exporter directly against the same table instead,
  run it with `create_schema: false`. Bilis owns the DDL. Note that this
  suppresses schema-creation DDL but the exporter still issues `DESC TABLE` at
  startup for optional column detection — it is not literally insert-only,
  whatever its README says.
