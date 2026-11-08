---
title: Alerting
description: Threshold rules over logs, traces and metrics, with Prometheus-style pending, firing and resolved states, delivered by email, Slack or a signed webhook.
order: 3
---

An alert rule watches one number from your telemetry and tells you, once, when
it crosses a line — and once more when it comes back. Rules live on the
**Alerts** page of your team. They are evaluated on a schedule by querying the
data Bilis already stores, so creating a rule never slows ingest down, and a
service that goes completely silent can still be noticed.

## What a rule is

A rule is a query, a condition and a few timings:

- **Source** — logs, traces or metrics, in one or more of the team's projects.
- **Filters and an aggregation** — what to count or measure (below).
- **Group by** (optional) — split the result into one series per value, for
  example one per `service.name`. Each series is alerted on separately.
- **Condition** — `>`, `>=`, `<` or `<=` a threshold.
- **Window** — how far back each evaluation reads: 1 minute to 24 hours.
- **Interval** — how often the rule is evaluated: every 1, 5 or 15 minutes.
- **For**, **keep firing for** and **no data** — when a crossing becomes an
  alert and when it stops being one (below).
- **Severity** (`info`, `warning`, `critical`) and **channels** — carried into
  the notification and where it is sent.

The rule editor draws the last 24 hours of the rule's reading with the
threshold as a line, so you can see how often it would have fired before you
save it. For a log or span `count`, the preview's buckets are rescaled to the
rule's window, so the line is comparable with the threshold.

## Sources and aggregations

| Source  | Filters                                              | Aggregations                                                                                                                                                             | Group by          |
| ------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------- |
| Logs    | services, severities, full-text search over the body | `count`, `rate` (per minute), `ratio` (matching lines ÷ every line of the same projects and services)                                                                    | `service.name`    |
| Traces  | services, span name, span kind                       | `count` of spans, `error_ratio` (share with status Error), `p50` / `p95` / `p99` of span duration in milliseconds                                                        | `service.name`    |
| Metrics | one metric, plus `attribute = value` filters         | the metric read as the explorer reads it (gauge value, counter as a rate), reduced over the window by `avg`, `max`, `min` or `last`; histograms by `p50` / `p95` / `p99` | any attribute key |

A log `ratio` narrows only the numerator: "error lines from checkout" is read
as a share of everything checkout logged. A percentile only makes sense on a
histogram, and `avg` does not make sense on one — a rule that asks for either
is marked as failing with that reason rather than quietly reading something
else.

## States: pending, firing, resolved

Rules follow the semantics of
[Prometheus alerting rules](https://prometheus.io/docs/prometheus/latest/configuration/alerting_rules/),
and the no-data setting follows
[Grafana alerting](https://grafana.com/docs/grafana/latest/alerting/), so if you
have written rules for either, these behave the way you expect.

Each series of a rule is an **alert instance** in one of three states:

- **Inactive** — the condition is false.
- **Pending** — the condition is true, but not yet for long enough.
- **Firing** — the condition has been true for at least **for**.

Only two moments are notified: an instance starting to fire, and a firing
instance resolving. Going pending and dropping back is silent.

- **For** (0 to 60 minutes) — how long the condition must hold before a pending
  instance fires. With `0` it fires on the first evaluation that crosses.
- **Keep firing for** (0 to 60 minutes) — how long a firing instance stays
  firing after its condition turns false. Use it to stop a value that hovers
  around the threshold from firing and resolving over and over. If the
  condition comes back true in that time, nothing is sent.

### No data

A series Bilis has seen before can disappear: the service stopped, the host
went away, the metric is no longer exported. What that means is the rule's
**no data** setting, per rule:

- **Treat as OK** (`inactive`) — the condition is false; a firing instance
  resolves.
- **Fire** (`firing`) — the condition is true; use this to be told when
  something goes silent.
- **Keep the last state** (`keep_last`) — leave the instance exactly as it
  was.

Only a series that had data once can be missing, so a brand-new rule that has
never matched anything does not fire on no data. An ungrouped log or span
`count` never has no data — an empty window counts `0` — so "fewer than 1 error
line in 5 minutes" works as a heartbeat rule. A ratio or a percentile over an
empty window has no value and is no data. A series that has had no data for a
day and is not firing is forgotten.

### When Bilis cannot read

If ClickHouse is overloaded or unreachable, the evaluation changes **no**
instance — nothing fires or resolves because of an outage. The rule is marked
as failing with the reason, shown on the Alerts page, and one **rule error**
notification is sent when a rule starts failing (not on every failed
evaluation, and not when it recovers).

## Evaluation timing

Every minute, Bilis dispatches an evaluation for each rule that is due. An
evaluation reads the window that ends at the current time **minus one minute**,
rounded down to the rule's interval, and the window is closed-open
(`[end − window, end)`), so two evaluations never count a row twice. The
one-minute lag gives batching SDKs and collectors time to deliver.

Expect a notification roughly **one to two minutes, plus the rule's interval,
plus its `for`**, after the moment the data crossed the threshold. A missed
evaluation (a restart, a deploy) is skipped, never replayed: after an outage
each rule is evaluated once, on its next slot. The Alerts page shows a banner
when rules are running more than one interval late.

## Channels

Channels are set up once per team, under **Team settings → Alert
notifications** (owners and admins), and each rule picks the channels it sends to. A
channel can send a test message, and shows its last delivery failure.

- **Email** — to every team member, or to a list of addresses.
- **Slack** — a Slack
  [incoming webhook](https://api.slack.com/messaging/webhooks) URL. No bot and
  no OAuth: create the webhook in Slack, paste its URL. It must be on
  `hooks.slack.com`.
- **Webhook** — a signed `POST` to any public https URL. Use it for anything
  Bilis does not speak natively, such as an on-call service's inbound
  integration.

Failed deliveries are retried ten times over about three hours, backing off
from 30 seconds to an hour between tries; a URL Bilis refuses to call fails at once.
**Muting** a rule (for an hour, a day or a week) keeps evaluating it and
recording history, and sends nothing. A disabled channel sends nothing.

### Allowed webhook destinations

Slack and webhook URLs are called from the Bilis server, so they are checked
before every send:

- `https://` only, on the default port, without a user name or password.
- The host must resolve **only** to public addresses: loopback, private,
  link-local, carrier-grade NAT, cloud metadata and other reserved ranges,
  IPv4 and IPv6, are refused. IP addresses must be written in plain dotted
  form.
- The request goes to the address that was checked, so a DNS answer that
  changes between the check and the connection is never used.
- Redirects are not followed, and a request gives up after 5 seconds.

## The webhook payload

Each delivery is a JSON body following
[Standard Webhooks](https://www.standardwebhooks.com/):

```json
{
    "type": "alert.firing",
    "timestamp": "2026-11-08T11:59:00Z",
    "data": {
        "event_id": 4812,
        "rule": {
            "id": 17,
            "name": "Checkout error spike",
            "severity": "warning"
        },
        "labels": { "service.name": "checkout" },
        "value": 87.0,
        "threshold": 25.0,
        "condition": ">",
        "state": "firing",
        "severity": "warning",
        "window": {
            "from": "2026-11-08T11:54:00Z",
            "to": "2026-11-08T11:59:00Z"
        },
        "error": null,
        "links": {
            "explorer": "https://your-bilis-host/acme/logs?from=2026-11-08T11%3A54%3A00Z&to=2026-11-08T11%3A59%3A00Z&service=checkout&severity%5B0%5D=error&severity%5B1%5D=fatal",
            "rule": "https://your-bilis-host/acme/alerts/17/edit"
        }
    }
}
```

`type` is one of `alert.firing`, `alert.resolved`, `alert.rule_error` (with
`data.error` set), `alert.issue_new` and `alert.issue_regressed` (see
[Issue events](#issue-events); `data.rule` is `null`) and `alert.test`.
`links.explorer` opens the logs, traces or metrics view filtered to the rule
and the evaluated window — or, for an issue event, the issue's own page.
`data.issue` is `null` for rule events; for an issue event it describes the
issue:

```json
"issue": {
  "project": "shop",
  "fingerprint": "9f2c41d0a7b3e8155c0e6d2b14f8a93e7d61c0b2a45f9e3871d6c2b0e4a7f915",
  "type": "TypeError",
  "message": "Cannot read properties of undefined (reading 'total')",
  "service": "checkout",
  "events": 3,
  "first_seen": "2026-11-08T11:58:41.512Z",
  "resolved_at": null
}
```

`resolved_at` is set on `alert.issue_regressed` — when you had resolved it.
`data.state` is `new` or `regressed`, and `rule`, `value`, `threshold` and
`window` are `null`.

### Verifying the signature

Every request carries three headers:

| Header              | Value                                                                  |
| ------------------- | ---------------------------------------------------------------------- |
| `webhook-id`        | `msg_<event id>` — the same on every retry, so you can de-duplicate    |
| `webhook-timestamp` | Unix seconds when this attempt was sent                                |
| `webhook-signature` | `v1,<base64 HMAC-SHA256>` of `<webhook-id>.<webhook-timestamp>.<body>` |

When you create a webhook channel, Bilis generates a signing secret
(`whsec_…`) and shows it **once**; you can also paste your own. Verify with any
[Standard Webhooks library](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries)
— they take the `whsec_` secret as is. In Node:

```js
import { Webhook } from 'standardwebhooks';

const wh = new Webhook(process.env.BILIS_WEBHOOK_SECRET); // whsec_...

// rawBody: the exact bytes received, before any JSON parsing
const event = wh.verify(rawBody, request.headers); // throws if invalid
```

In Python:

```python
from standardwebhooks.webhooks import Webhook

wh = Webhook(os.environ["BILIS_WEBHOOK_SECRET"])
event = wh.verify(raw_body, request.headers)  # raises if invalid
```

Without a library: strip `whsec_`, base64-decode the rest to get the key,
compute HMAC-SHA256 over `id.timestamp.body` with the **raw** body, base64 it,
and compare it in constant time with each space-separated `v1,` entry of
`webhook-signature`. Reject a timestamp more than five minutes from your
clock. Answer with any `2xx` once the event is accepted; anything else is
retried.

## Issue events

Besides rules, a channel can opt in to **issue events** (a switch on the
channel): a message when a **new issue** is first seen, and when a resolved
issue **regresses** — is seen again after you resolved it. These are checked
every minute, are sent once per issue and occurrence, and go to every enabled
channel of the team that has the switch on, independent of any rule.

Their titles read `[NEW ISSUE] TypeError: Cannot read properties of undefined
— checkout` or `[REGRESSED ISSUE] …`, and link to the issue's page. Issue
events go **only** to channels with "issue events" on — never to a rule's
channels. One check sends at most 50 new-issue messages per team; anything
beyond that is still listed on the Issues page, just not announced.

Two limits come from how issues are watched:

- "New" goes by when the issue was first seen according to the timestamp your
  application sent. An exception that arrives stamped more than about three
  minutes in the past — a client with a wrong clock, or events buffered
  offline and sent late — still appears on the Issues page, but never sends a
  "new issue" message.
- A regression is noticed only if Bilis checks within about three minutes of
  the event arriving. If the scheduler was down for longer, the regression is
  visible on the Issues page and is announced on the issue's next event.

## Monitoring the alerter

An alerting system that has quietly stopped looks exactly like a quiet night.
Point an external uptime check at:

```text
GET https://your-bilis-host/health/alerting
```

It needs no authentication and returns nothing but the evaluation loop's
heartbeat:

| Response                              | Meaning                                   |
| ------------------------------------- | ----------------------------------------- |
| `200 {"status":"ok","lag":12}`        | evaluations ran 12 seconds ago            |
| `503 {"status":"stale","lag":412}`    | no evaluation for more than three minutes |
| `503 {"status":"missing","lag":null}` | evaluations have never run                |
| `200 {"status":"disabled"}`           | alerting is switched off on this instance |

On a self-hosted install, the scheduler container's healthcheck fails on the
same condition, and alerting needs both the scheduler and the queue worker
running — evaluations and notifications run on the `alerts` queue, which
Horizon always consumes. `BILIS_ALERTING_ENABLED=false` switches alerting off
entirely.

## Limits

| Limit                       | Value                                         |
| --------------------------- | --------------------------------------------- |
| Evaluation interval         | 60 s minimum (1, 5 or 15 minutes)             |
| Window                      | 60 s to 24 h; **at most 1 h for trace rules** |
| `for` and `keep firing for` | up to 1 h each                                |
| Series per rule             | 100 — the most extreme values are kept        |
| Alert history               | 90 days                                       |
| Channel test messages       | 10 a minute                                   |

On [bilis.app](https://bilis.app) the Free plan counts **10 alert rules per
team**. Like every Free-plan number it is soft: going over is shown on the plan
card, and no rule is ever refused or switched off because of it. See
[Limits and behavior](/docs/reference/limits-and-behavior#the-hosted-free-plan).

A trace rule reads spans directly, so its window is capped at an hour. A rule
grouped into more than 100 series keeps the 100 furthest past the threshold
(highest for `>`/`>=`, lowest for `<`/`<=`); the rest keep their previous state
until they are back in the top 100.

What alerting deliberately does not do: anomaly detection, rules with several
conditions, escalation, on-call schedules, acknowledgements, repeat reminders
while something keeps firing, scheduled silences (only "mute until"), or
evaluation more often than once a minute. The webhook channel is the way into
the tools that do.
