---
title: Timestamps
description: What Bilis accepts as a time, how it is normalised, and why event time is not arrival time.
order: 2
---

Every log line is stored with one timestamp, in a ClickHouse
`DateTime64(9, 'UTC')` column. Nine fractional digits: nanosecond resolution,
always UTC.

## Accepted formats

The simple JSON endpoint takes whatever your shipper already has:

| Input                      | Example                          | Read as                        |
| -------------------------- | -------------------------------- | ------------------------------ |
| ISO 8601 with an offset    | `2026-08-26T14:02:11.418+02:00`  | That instant, converted to UTC |
| ISO 8601 without an offset | `2026-08-26 12:02:11.418`        | **Assumed UTC**                |
| Unix seconds               | `1756211400` or `1756211400.418` | Seconds                        |
| Unix milliseconds          | `1756211400123`                  | Milliseconds                   |
| Unix microseconds          | `1756211400123456`               | Microseconds                   |
| Unix nanoseconds           | `1756211400123456789`            | Nanoseconds                    |

Numeric values are disambiguated **by digit count**, not by a unit field:
18 digits or more is nanoseconds, 15–17 is microseconds, 12–14 is milliseconds,
and anything shorter is seconds. That covers every unix epoch value from 1970
to well past 2200 without you having to declare a unit.

The OTLP endpoint uses OTLP's own field, `timeUnixNano`, which is always
nanoseconds.

If a value cannot be understood at all, the record is not rejected — it falls
back to arrival time.

> **Note:** a naive timestamp is assumed to be UTC, not local. If your shipper
> emits `2026-08-26 14:02:11` in Europe/Bratislava, the line will land two hours
> in the future. Send an offset.

## Event time and arrival time

Two different instants exist for any log line:

- **Event time** — when the thing happened, according to the client.
- **Arrival time** — when Bilis received the request.

Bilis stores the client's event time when there is one, and falls back to
arrival time when there is not. OTLP has both fields, and the fallback order is
exactly: `timeUnixNano`, then `observedTimeUnixNano`, then arrival.

This matters because **shippers buffer**. A Monolog handler that flushes after
the response, a collector with a persistent sending queue, a mobile client that
was offline for an hour — all of them deliver lines minutes or hours after the
event. If arrival time won, a queue drain would stack thousands of lines onto
one instant and your incident timeline would be fiction. Event time keeps the
ordering that actually happened.

The consequence to know about: **a line can appear "in the past".** A batch
delivered at 14:20 carrying events from 14:02 lands at 14:02 in the viewer,
behind where you were looking. If a line seems missing, widen the time range
before you suspect ingest.

## Precision is preserved

Nanoseconds are formatted with string arithmetic — never round-tripped through
a float or a `DateTime` object, both of which would quietly truncate. What you
send at nanosecond resolution is what is stored:

```text
1756211400123456789  ->  2026-08-26 12:30:00.123456789
```

Most shippers only have milliseconds, which is fine; the remaining digits are
zero-padded.
