---
title: Metrics
description: Sending OTLP metrics over HTTP, per-SDK setup, temporality, one Collector pipeline, and what the explorer draws for each metric type.
order: 3
---

Bilis accepts OpenTelemetry metrics at one endpoint, in the same two encodings
the logs and traces endpoints take, with the same API key and the same
never-blame-the-client contract.

| Endpoint               | Payload                                                  | Success |
| ---------------------- | -------------------------------------------------------- | ------- |
| `POST /api/v1/metrics` | OTLP `ExportMetricsServiceRequest`, JSON **or** protobuf | `200`   |

One row is stored per data point. All five OTLP metric types are accepted —
gauge, sum, histogram, exponential histogram and summary — each into its own
table. Points that cannot be stored are skipped and counted in an OTLP
`partialSuccess` response; a storage failure answers `503` with
`Retry-After: 5`. Bilis never returns a `4xx` for the contents of a payload —
OTel clients treat `4xx` as permanent and drop the batch. Bodies sent with
`Content-Encoding: gzip` or `deflate` are inflated; anything else (`zstd`,
`snappy`, …) answers `415`. The endpoint shares its rate limit with the other
ingest endpoints.

## gRPC is not supported

**This is the thing that will look like an outage and is not one.** The
OpenTelemetry Collector and most SDKs default to OTLP over **gRPC on port
4317**. Bilis speaks OTLP over **HTTP only**, for metrics exactly as for logs
and traces. Point your exporter at the HTTP protocol explicitly:

```bash
OTEL_METRICS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_METRICS_PROTOCOL=http/protobuf   # or http/json
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=https://your-bilis-host/api/v1/metrics
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here
```

The per-signal `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` is used **verbatim**, so
it names the full path. If you set the signal-agnostic
`OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api` instead, the SDK
appends `/v1/metrics` itself — which is why that value ends in `/api`. Set to
the bare host, an SDK would post to `/v1/metrics`, which does not exist here.

### The header value is url-encoded, except where it is not

The key goes in `Authorization: Bearer bilis_...` or in `X-Bilis-Key`.
`OTEL_EXPORTER_OTLP_HEADERS` is specified as url-encoded, so the bearer form is
written `Authorization=Bearer%20bilis_...`. The Collector and the Go SDK decode
that; **the PHP SDK does not**, and sends the literal `%20`, which comes back
`401` and looks exactly like a wrong key. `x-bilis-key=bilis_...` has no space
to encode and is correct everywhere — prefer it. The
[Traces](/docs/ingestion/traces#the-header-value-is-url-encoded-except-where-it-is-not)
page has the full story.

## Quickstart per SDK

Each SDK collects in memory and exports on a timer. The timer is
`OTEL_METRIC_EXPORT_INTERVAL`, in milliseconds, and defaults to `60000` — so
the first points arrive up to a minute after the process starts. Lower it while
you are checking the setup, and put it back afterwards: every export writes a
point per series.

Set `OTEL_SERVICE_NAME` in every case. It is the service filter in the metrics
explorer, and it is what makes a metric line up with the same service's logs
and traces.

### Node

```bash
npm install @opentelemetry/api @opentelemetry/auto-instrumentations-node
```

```bash
OTEL_SERVICE_NAME=checkout
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here

node --require @opentelemetry/auto-instrumentations-node/register app.js
```

The signal-agnostic endpoint serves traces and metrics from one line. The HTTP
instrumentation records request durations as histograms without any code; your
own instruments come from `metrics.getMeter('checkout')` in
`@opentelemetry/api`.

### Python

```bash
pip install opentelemetry-distro opentelemetry-exporter-otlp-proto-http
opentelemetry-bootstrap -a install
```

```bash
OTEL_SERVICE_NAME=checkout
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here

opentelemetry-instrument python app.py
```

The Python distro defaults to gRPC, so `OTEL_EXPORTER_OTLP_PROTOCOL` is not
optional here.

### Go

The exporter is wired in code, and is protobuf-only, which Bilis decodes:

```go
exporter, err := otlpmetrichttp.New(ctx,
	otlpmetrichttp.WithEndpoint("your-bilis-host"),
	otlpmetrichttp.WithURLPath("/api/v1/metrics"),
	otlpmetrichttp.WithHeaders(map[string]string{
		"Authorization": "Bearer " + os.Getenv("BILIS_API_KEY"),
	}),
	otlpmetrichttp.WithCompression(otlpmetrichttp.GzipCompression),
)
if err != nil {
	return err
}

provider := sdkmetric.NewMeterProvider(
	sdkmetric.WithReader(sdkmetric.NewPeriodicReader(exporter,
		sdkmetric.WithInterval(60*time.Second),
	)),
	sdkmetric.WithResource(resource.NewSchemaless(semconv.ServiceName("checkout"))),
)
otel.SetMeterProvider(provider)
defer provider.Shutdown(ctx)
```

`OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` replaces the `WithEndpoint` /
`WithURLPath` pair if you would rather configure it from the environment.
`otelhttp` records server request durations once a meter provider is set.

### PHP and Laravel

With `keepsuit/laravel-opentelemetry` — the package Bilis itself uses, set up
as on the [Traces](/docs/ingestion/traces#php-and-laravel) page — metrics are
one variable away. The package ships with the metrics exporter set to `otlp`;
point it at Bilis rather than switching it off:

```bash
OTEL_SERVICE_NAME=checkout
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-bilis-host/api
OTEL_EXPORTER_OTLP_HEADERS=x-bilis-key=bilis_your_key_here
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=null
```

Out of the box it records `http.server.request.duration` and
`db.client.operation.duration` as histograms. Your own instruments come from
the `Meter` facade:

```php
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;

Meter::counter('orders.placed')->add(1, ['payment.method' => 'card']);
Meter::histogram('checkout.duration', 's')->record(0.42);
```

A PHP-FPM request is a short-lived process, so the package exports its metrics
when the request ends; under Octane or a queue worker it collects and exports
on an interval (`OTEL_WORKER_MODE_COLLECT_INTERVAL`, 60 seconds by default).

With the official `opentelemetry-auto-laravel` and `ext-opentelemetry`, the
same four `OTEL_EXPORTER_OTLP_*` lines apply; set `OTEL_METRICS_EXPORTER=otlp`.

## Temporality: cumulative or delta, both work

A counter or histogram can be reported two ways. **Cumulative** — the default in
every SDK and the Collector — sends the running total since the process
started. **Delta** sends only what changed since the last export. Bilis stores
either as it arrives and the explorer reads each correctly, so there is nothing
to configure: leave the SDK default alone.

If something upstream insists on one — Claude Code, for instance, exports delta
by default — that is fine too. `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE`
(`cumulative` or `delta`) is the standard switch if you want to choose.

Cumulative counters restart at zero when a process restarts. The explorer
treats a value lower than the one before, or a changed start time, as a reset
and counts the new value as the increase, so a deploy does not show up as a
negative spike.

## Collector configuration

If a Collector sits between your services and Bilis, add a `metrics` pipeline
to the same `otlphttp` exporter the logs and traces use. Like `logs_endpoint`
and `traces_endpoint`, **`metrics_endpoint` is used verbatim**, so it names the
full path. The exporter's base `endpoint` setting would append `/v1/metrics` to
whatever you give it, and `https://your-bilis-host/v1/metrics` does not exist:

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
        metrics_endpoint: https://your-bilis-host/api/v1/metrics
        headers:
            Authorization: Bearer bilis_your_key_here
        compression: gzip
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
            processors: []
            exporters: [otlphttp/bilis]
        traces:
            receivers: [otlp]
            processors: []
            exporters: [otlphttp/bilis]
        metrics:
            receivers: [otlp]
            processors: []
            exporters: [otlphttp/bilis]
```

The reasoning behind the queue, the storage and the missing `batch` processor
is on the [Shippers](/docs/ingestion/shippers#opentelemetry-collector) page.
Host metrics work the same way: a `hostmetrics` receiver added to the
`metrics` pipeline's `receivers` sends CPU, memory, disk and network figures
for the box the Collector runs on.

## Sending a data point by hand

A gauge and a monotonic counter in one export:

```bash
curl -X POST https://your-bilis-host/api/v1/metrics \
  -H "Authorization: Bearer bilis_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{
    "resourceMetrics": [{
      "resource": {"attributes": [
        {"key": "service.name", "value": {"stringValue": "checkout"}}
      ]},
      "scopeMetrics": [{
        "metrics": [
          {
            "name": "queue.depth",
            "unit": "{job}",
            "gauge": {"dataPoints": [{
              "timeUnixNano": "1756550400000000000",
              "asDouble": 42,
              "attributes": [
                {"key": "queue", "value": {"stringValue": "emails"}}
              ]
            }]}
          },
          {
            "name": "orders.placed",
            "unit": "{order}",
            "sum": {
              "aggregationTemporality": 2,
              "isMonotonic": true,
              "dataPoints": [{
                "startTimeUnixNano": "1756546800000000000",
                "timeUnixNano": "1756550400000000000",
                "asInt": "1284"
              }]
            }
          }
        ]
      }]
    }]
  }'
```

`asInt` is a **string** in OTLP JSON — a 64-bit integer, which JSON numbers
cannot carry exactly — and `asDouble` is a number. Times are nanoseconds since
the epoch, as strings. `aggregationTemporality` takes the enum number (`1`
delta, `2` cumulative) or its name (`AGGREGATION_TEMPORALITY_CUMULATIVE`), and
both the camelCase and snake_case field spellings are accepted.

## What the explorer does with each type

The metrics page (**Metrics** in the sidebar) has two tabs. **Hosts** is for
machines running the [server agent](/docs/ingestion/server-agent) or any
Collector with the host-metrics receiver: every host with its current CPU,
memory and disk, and a fixed set of charts for one. **Explorer** charts one
metric at a time. Pick a metric, narrow it by service and by up to five
attribute equalities, and optionally group the lines by one attribute. An
attribute is looked up on the data point first and on its resource second, so
resource attributes such as `host.name` and `container.name` work too. The
picker lists what has reported in the last 24 hours.

| Metric type                  | Drawn as                                                  |
| ---------------------------- | --------------------------------------------------------- |
| Gauge                        | A level: average, min, max or sum across the series       |
| Sum, monotonic, cumulative   | A per-second rate, from deltas per series, resets handled |
| Sum, monotonic, delta        | A per-second rate                                         |
| Sum, not monotonic (up-down) | A level, like a gauge                                     |
| Histogram, explicit buckets  | p50, p95 and p99, read off the merged buckets             |
| Histogram, exponential       | p50, p95 and p99, after rescaling the buckets to line up  |
| Summary                      | The quantiles the client stored — marked approximate      |

Summaries are marked approximate because their quantiles were computed by each
client over its own observations and cannot be merged exactly; where you have
the choice, send a histogram.

**Exemplars are stored** with the point they came with — value, time, trace id
and span id — so the link from a metric back to a request is kept. An exemplar
that cannot be stored is dropped on its own; the point it rode on is kept.

## Retention

Metric data points are kept for **30 days**, and the explorer's window is
capped at the same 30 days. The `ALTER` statements that change a retention
window are in
[Limits and behavior](/docs/reference/limits-and-behavior#changing-retention).

## Limits and rejections

A data point is rejected — counted in `partialSuccess.rejectedDataPoints`, the
rest of the export kept — when:

- its time is missing, or falls outside **2000-01-01 to 2106-01-01**. Metric
  time is stored in whole seconds (the OpenTelemetry ClickHouse schema's
  `DateTime`), so sub-second precision is truncated and the window ends where
  that type does. A start time that cannot be stored is kept as "not set"
  rather than rejecting the point;
- its value is `NaN` or `±Infinity`;
- it is a histogram whose bucket counts do not number exactly one more than its
  explicit bounds;
- a count is not a non-negative integer, or `flags` does not fit 32 bits.

A metric with no data kind Bilis recognises counts as one rejected item. A
fully accepted export answers `{}`; a partial one looks like this, still `200`:

```json
{
    "partialSuccess": {
        "rejectedDataPoints": 3,
        "errorMessage": "Some data points could not be stored and were skipped."
    }
}
```

On the reading side, one chart reads at most **500 series** before grouping and
draws at most **10 groups**, ranked by volume; the chart says how many it left
out rather than dropping them silently. A series is one combination of service
and attribute values, and the sender decides how many there are.

## Sizing

A metric's cost is its cardinality times its export rate, not its traffic: a
counter bumped a million times a minute still exports one point per series per
interval. At the default 60-second interval, 1,000 series make 1,440 points a
day each — about 43 million points over the 30-day retention. Histograms cost
more per point than gauges and counters, because each point carries its bucket
arrays.

If it is too much, in order of what to try first: drop high-cardinality
attributes (user ids, request ids, full URLs) before they become series,
lengthen the export interval, or filter metrics you never chart at the
Collector.
