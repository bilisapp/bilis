---
title: Ingest never blames the client
description: Why the Bilis ingest endpoints answer 202 with a count instead of rejecting your batch, and what that costs us.
date: 2026-08-29
author: Samuel Vrablik
---

A logging pipeline has one job at the edge: do not lose the line. Everything
else — the schema, the search, the retention — happens after the bytes are
safely somewhere. So the first rule we wrote down for Bilis was that ingest
never returns `400`.

## What that means in practice

Send a batch of a thousand records where three are malformed, and Bilis takes
the nine hundred and ninety-seven. The response is `202 Accepted` with the
counts:

```json
{
    "accepted": 997,
    "skipped": 3
}
```

Not an error. Not a partial failure your shipper has to interpret. A number
you can graph.

## Why not just reject it?

Because the sender is almost never in a position to fix it. By the time a log
record reaches an ingest endpoint it has usually been through an SDK, a
collector, a queue and a retry — and the process that produced the bad field
exited some time ago. Rejecting the batch does not repair the record. It just
moves a fire from our side of the wire to yours, at the exact moment you are
already looking at a fire, which is why you are reading logs in the first
place.

## What it costs

Honesty about what "success" means. A `202` from Bilis means _queued_, not
_durable_: writes go to ClickHouse with `async_insert=1` and
`wait_for_async_insert=0`, so the rows are accepted into a buffer rather than
committed to disk. We would rather say that plainly than let a `200` imply a
guarantee we are not making.

The one thing that does fail loudly is us. If ClickHouse is unreachable, the
endpoint answers `503` with a `Retry-After` header, because that is a
condition your shipper genuinely can act on — by holding onto the batch and
trying again.
