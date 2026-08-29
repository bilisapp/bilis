---
title: The sort key leads with ProjectId
description: Why the Bilis logs table sorts by project first, why that is worth diverging from the upstream OpenTelemetry exporter for, and why it is not a tenancy boundary.
date: 2026-08-22
author: Samuel Vrablik
---

The whole of the Bilis storage design is four lines of DDL:

```sql
ENGINE = MergeTree
PARTITION BY toDate(Timestamp)
ORDER BY (ProjectId, Timestamp, ServiceName)
TTL toDateTime(Timestamp) + toIntervalDay(30)
```

Everything else in the table — the column names, the types, the codecs — belongs
to the OpenTelemetry ClickHouse exporter, and we copy it from a pinned collector
tag rather than inventing it. The sort key is the one part that is ours, and it
is the part every query lives or dies by.

## What the upstream exporter does instead

The exporter's own table leads with `toStartOfFiveMinutes(Timestamp)`. That is
the right call for the thing it is: a single stream of logs with no notion of
who they belong to. Bucketing the timestamp gives the sparse index something
coarse to prune on and keeps rows from the same five minutes together.

Bilis has a column the exporter does not: `ProjectId`, written on every row.
Once a query is always constrained to one project, the bucket earns much less —
and it costs something real. `ORDER BY Timestamp DESC` on a bucketed key is no
longer a read in sort order, which is exactly the shape the two views people
actually use are made of: the latest N lines, and a live tail that asks for the
latest N lines again a second later.

So we diverge, deliberately, and write down that we diverged. A `set` index on
`ServiceName` compensates for the service-filtered queries that the bucket would
otherwise have helped.

## What leading with the project buys

Two things, both boring, both worth it.

**Locality.** One project's month of logs is a contiguous run of granules
instead of a stripe smeared across every part in the table. A query for one
project reads the range it needs and skips the rest on the primary index.

**Compression.** Sorting puts similar rows next to each other, and ZSTD is much
happier with a run of lines from one service in one project than with the same
lines interleaved with everyone else's. Storage is the running cost of a log
product; the sort key is most of what determines it.

## Every query has to be written for it

A sort key only pays out if the `WHERE` clause matches its shape, so the base
predicate is built in exactly one method, and user filters append to it:

```sql
WHERE ProjectId IN (…)
  AND Timestamp >= {from:DateTime64(9)}
  AND Timestamp <= {to:DateTime64(9)}
ORDER BY Timestamp DESC
```

Plain predicates on the raw `Timestamp` column, no expression wrapped around it,
nothing derived. The rule that keeps this honest is that no filter may ever
replace the `ProjectId` predicate — a filter can only narrow what the base query
already constrained.

That is also why there is no `TimestampDate` column shadowing the partition key.
A derived column is an independent stored value, and a predicate on `Timestamp`
cannot prune a partition keyed on something else. Partition, TTL and sort key
all read the same column.

## It is clustering, not isolation

This is the part worth being loud about, because a sorting key that starts with
a tenant identifier looks like a security boundary and is not one.

`ORDER BY (ProjectId, …)` buys locality and compression. It buys exactly zero
isolation. Nothing about a sort key prevents a row from being read; it only
decides where the row is written. Isolation is authentication plus one of: a
server-side `ProjectId` predicate the user cannot remove, a ClickHouse row
policy, or separate tables per tenant. Bilis uses the first, which is why the
predicate is built in one place and why a filter can never replace it.

The other half of the same rule is where the id comes from. On ingest,
`ProjectId` is taken from the authenticated API key and nowhere else. It is
never read from the payload, whatever the payload claims about itself. In the
viewer it comes from the projects of the current team. A slug from a URL never
reaches SQL.

The column is declared `DEFAULT ''` — but only so that an INSERT from a stock,
unmodified exporter stays _valid_. It does not make such a row _correct_: rows
written without a project all land in one degenerate empty prefix and get no
locality at all. Bilis writes the value explicitly, every time.

None of this is clever. It is one ordering decision, written down with its
reasons, and then defended in every query that touches the table.
