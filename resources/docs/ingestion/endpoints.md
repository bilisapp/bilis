---
title: Endpoints
description: The two ingest endpoints, how they authenticate, and the response contract they promise.
order: 1
---

Bilis exposes two ingest endpoints. Both authenticate the same way, both write
into the same table, and both follow the same never-blame-the-client contract.

| Endpoint              | Payload                                               | Success |
| --------------------- | ----------------------------------------------------- | ------- |
| `POST /api/v1/logs`   | OTLP `ExportLogsServiceRequest`, JSON **or** protobuf | `200`   |
| `POST /api/v1/ingest` | Simple JSON: one object or an array of them           | `202`   |

A third path accepts what the Sentry SDKs send, for applications that already
report exceptions through one; it follows the same contract. See
[Sentry-compatible ingest](/docs/ingestion/sentry).

## Authentication

Send the project API key as a bearer token, or in `X-Bilis-Key` if the client
cannot set an `Authorization` header — see [API keys](/docs/ingestion/api-keys)
for how a key is issued, what its two halves are for, and how to revoke one:

```bash
-H "Authorization: Bearer bilis_YOUR_API_KEY"
# or
-H "X-Bilis-Key: bilis_YOUR_API_KEY"
```

The key is looked up by hash and resolved to exactly one project. **That project
id is the only one that will ever be written.** Nothing in the payload can set,
override or suggest a project — a resource attribute named `project.id` is just
an attribute. A missing or unknown key is the one case that does return a client
error: `401` with `{"message": "API key invalid."}`.

## OTLP: `POST /api/v1/logs`

Standard OTLP/HTTP, JSON encoding:

```bash
curl -X POST https://bilis.example.com/api/v1/logs \
  -H "Authorization: Bearer bilis_YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "resourceLogs": [{
      "resource": { "attributes": [
        { "key": "service.name", "value": { "stringValue": "checkout" } }
      ]},
      "scopeLogs": [{
        "scope": { "name": "checkout.payments" },
        "logRecords": [{
          "timeUnixNano": "1756211400123456789",
          "severityNumber": 17,
          "severityText": "ERROR",
          "body": { "stringValue": "Card declined for order 41902" },
          "attributes": [
            { "key": "order.id", "value": { "stringValue": "41902" } }
          ]
        }]
      }]
    }]
  }'
```

Both the camelCase and snake_case spellings of the OTLP JSON fields are
accepted (`timeUnixNano` / `time_unix_nano`, and so on).

### Protobuf

`Content-Type: application/x-protobuf` (or `application/protobuf`) is accepted
too, and produces exactly the same rows as the JSON encoding of the same
export. That matters because most OTel SDKs cannot speak OTLP/JSON at all — the
Go, Java, .NET and Rust HTTP exporters are protobuf-only — so this is what lets
them point straight at Bilis.

It is decoded in-process, in **pure PHP**, by `app/Services/Ingest/Protobuf`:
about three hundred lines reading the wire format, no composer package and no
`ext-protobuf`. Every message is a method named after its `.proto` message, and
unknown fields are skipped rather than refused, so a newer collector talking to
an older Bilis keeps working.

Because it parses untrusted binary, it has an off switch:

```bash
BILIS_OTLP_PROTOBUF=false
```

With that set, a protobuf export answers `415` with the old hint, and only the
JSON path is reachable:

```json
{
    "message": "Only the OTLP JSON encoding is supported. Set OTEL_EXPORTER_OTLP_PROTOCOL=http/json and send Content-Type: application/json."
}
```

**gRPC on port 4317 is still not supported** and will not be: PHP is a poor
gRPC server, and a Collector already bridges that hop.

### Compression

Both endpoints inflate a body sent with `Content-Encoding: gzip` or `deflate` —
which the Collector's `otlphttp` exporter does by default. Anything else
(`zstd`, `snappy`, `lz4`, `br`) answers `415` naming what is supported, because
no amount of retrying makes such a body readable; configure the exporter
instead.

A decompressed body is capped (`BILIS_INGEST_MAX_DECOMPRESSED_BYTES`, 32 MB by
default) so a compression bomb cannot be traded for the server's memory. A body
that hits the cap is discarded whole rather than half-parsed.

### Responses

A fully accepted export answers `200` with an empty JSON object, as OTLP
requires:

```json
{}
```

When some records could not be mapped, the healthy ones are still stored and
the rest are reported through OTLP's partial success field — still `200`:

```json
{
    "partialSuccess": {
        "rejectedLogRecords": 2,
        "errorMessage": "Some log records could not be parsed and were skipped."
    }
}
```

## Simple JSON: `POST /api/v1/ingest`

For anything without an OTel exporter. The body is one log object or a list of
them. Only the message is required:

```json
[
    {
        "message": "Card declined for order 41902",
        "level": "error",
        "service": "checkout",
        "timestamp": "2026-08-26T14:02:11.418+02:00",
        "context": { "order_id": "41902", "attempt": 3 }
    },
    { "message": "Retrying in 8s", "level": "warn", "service": "checkout" }
]
```

Recognised fields, with the aliases each accepts:

| Field       | Aliases        | Notes                                                                   |
| ----------- | -------------- | ----------------------------------------------------------------------- |
| `message`   | `body`         | Required. Non-strings are stringified; objects are JSON-encoded.        |
| `level`     | `severity`     | See [Severity](/docs/ingestion/severity).                               |
| `timestamp` | `time`         | See [Timestamps](/docs/ingestion/timestamps). Defaults to arrival time. |
| `service`   | `service_name` | Filterable in the viewer.                                               |
| `context`   | `attributes`   | Flattened to a string map.                                              |
| `trace_id`  | `traceId`      | Stored as-is.                                                           |
| `span_id`   | `spanId`       | Stored as-is.                                                           |
| `scope`     | —              | Logger or component name.                                               |
| `event`     | —              | Event name.                                                             |

The response is `202 Accepted` with counts, plus a `message` when something was
skipped:

```json
{ "accepted": 12, "skipped": 1 }
```

A record with no usable message is skipped and counted. A body that is not JSON
at all counts as one skipped record — still `202`, never `400`.

## The never-400 contract

**Ingest does not return `4xx` for a bad payload.** This is deliberate, and it
is a correctness rule rather than politeness:

- OTel clients treat `4xx` as **permanent** and drop the batch. A malformed
  field in one record would silently destroy the whole export, including the
  records that were fine.
- They treat `5xx` as **retryable**. So a storage problem must present as `5xx`,
  or the data is gone.

The two things that follow from that:

1. **Bad records are skipped and counted, never rejected.** One unparseable
   record does not cost you the other 499 in the batch.
2. **Storage failures return `503` with `Retry-After: 5`** — every ClickHouse
   error, overload or not:

    ```http
    HTTP/1.1 503 Service Unavailable
    Retry-After: 5

    {"message": "Log storage is temporarily unavailable. Please retry."}
    ```

Correct status codes buy more effective availability here than a second server
would. The client is never blamed for a problem on our side.

> **Note:** the payload is acknowledged first and parsed best-effort after. If
> you need to know whether a specific record made it, check the counts in the
> response — not the status code.
