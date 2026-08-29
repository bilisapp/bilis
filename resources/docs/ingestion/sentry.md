---
title: Sentry-compatible ingest
description: Bilis speaks the Sentry SDKs' ingest protocol, so an SDK you already have can ship exceptions here as searchable error logs.
order: 8
---

Sentry's SDKs are good at one thing Bilis does not do itself: catching an
exception in your application and packaging it with the context around it.
Bilis implements the ingest protocol those SDKs speak, so you can point one at
Bilis and have its exceptions land in your log stream.

This is protocol compatibility for log ingestion, and nothing more. Bilis is a
log store: exceptions arrive as `ERROR` records in the same table, searchable
over the same time range as everything else you ship. There is no issue list,
no grouping, no assignment and no resolve button, and none is planned. If you
want error tracking, you want Sentry — this is for putting the exceptions your
app already reports next to the logs that explain them.

> Sentry is a trademark of Functional Software, Inc. Bilis is not affiliated
> with, endorsed by, or a replacement for it; the SDKs remain theirs, and this
> page only describes an endpoint that accepts what they send.

## Pointing an SDK at Bilis

An SDK is configured with a DSN, which carries the
[public half of an API key](/docs/ingestion/api-keys#the-public-half). Copy it
from **Projects → your project**, under the key you want to use:

```
https://bilis_pk_YOUR_PUBLIC_KEY@bilis.example.com/1
```

Set it wherever the SDK reads its DSN:

```bash
SENTRY_DSN="https://bilis_pk_YOUR_PUBLIC_KEY@bilis.example.com/1"
```

That is the whole setup. The SDK builds its own endpoint from the DSN and posts
envelopes to `POST /api/{id}/envelope/`, which is why the path is Sentry's shape
rather than `/api/v1`.

The project id at the end of the DSN is ignored. The project is always the one
the public key belongs to, exactly as it is for the other ingest endpoints.

## What is stored

One envelope `event` becomes one log record:

| Log field       | Comes from                                                      |
| --------------- | --------------------------------------------------------------- |
| Body            | `Type: message` of the thrown exception, or the event's message |
| Severity        | The SDK's level (`warning` becomes `WARN`)                      |
| Service         | The `service` tag, falling back to `server_name`                |
| Trace / span id | `contexts.trace`, which is already OpenTelemetry's hex          |
| Timestamp       | The event timestamp                                             |

Everything else is flattened into attributes, under the OpenTelemetry names the
rest of your logs already use rather than the ones on the wire:

| Attribute                                     | Was                                  |
| --------------------------------------------- | ------------------------------------ |
| `exception.type`, `exception.message`         | the thrown exception                 |
| `exception.stacktrace`                        | the frames, most recent first        |
| `exception.origin`                            | the innermost frame in your own code |
| `exception.handled`, `exception.mechanism`    | how it was caught                    |
| `service.version`, `service.dist`             | release, dist                        |
| `deployment.environment`                      | environment                          |
| `transaction.name`, `logger.name`, `event.id` | transaction, logger, event id        |
| `host.name`                                   | server name                          |
| `telemetry.sdk.name`, `.version`, `.language` | which client reported it             |
| `process.runtime.name`, `.version`            | the runtime context                  |
| `tag.*`, `extra.*`, `user.*`, `http.*`        | tags, extras, user, request          |
| `breadcrumbs`                                 | breadcrumbs, newest first            |

Search them the way you search any attribute — `exception.type` is a good place
to start when you want one class of failure.

## What is not stored

An SDK sends more than events. Performance transactions, sessions, attachments,
minidumps and client reports all belong to features Bilis does not have, so they
are counted and dropped. The SDK is never told they failed — it would only retry
them.

Tracing is out of scope, so leave `traces_sample_rate` at zero; a transaction
that is never sent is bandwidth you keep.

## From the browser

A page can only post to Bilis from an origin you have listed. Add it under
**Projects → your project → Browser origins**, one per line:

```
https://shop.example.com
https://*.staging.example.com
```

Scheme and host, plus a port if it is not the default. A leading `*.` stands
for exactly one subdomain label, so `https://*.example.com` covers
`https://app.example.com` and not `https://a.b.example.com`. A lone `*` allows
any origin, which is worth doing only while you are testing.

An empty list means no browser may post at all, which is the right setting for
a project that only ships from servers. Nothing is rejected outright — the
request may still reach Bilis — but without a header naming its origin the
browser discards the response, and the SDK is told its request went nowhere.

This is per project, not per key, because it describes where your application
is served from. It is also why the [public key](/docs/ingestion/api-keys) being
readable costs you little: the key alone is not enough to post from a page you
do not control.

## Compatibility

- Server-side SDKs work as they are.
- Browser SDKs work once their origin is listed above.
- The older `POST /api/{id}/store/` endpoint is accepted too.
- Gzipped bodies, which most SDKs send by default, are inflated.

Everything else follows the usual ingest contract: a payload Bilis cannot read
is counted and dropped, never answered with a client error. See
[Limits and behavior](/docs/reference/limits-and-behavior).
