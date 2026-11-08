# Alerting — Specification

Status: agreed 2026-11-08; built 2026-11-08 on `feature/alerting`. Settled traps
and decisions: `.ai/rules/alerting.md`; public docs: `resources/docs/reference/alerting.md`.

## Purpose and scope

Tell a team, reliably and once, that something in their telemetry crossed a line — and again when it
came back. Built on the query services Bilis already has, without touching the ingest path.

- **In:**
  - **Threshold rules** over logs, traces and metrics: count, rate, ratio, average and percentile,
    over a window, optionally grouped by one attribute, with a `for` duration.
  - **No-data alerting**: a series that disappears can fire.
  - **Notifications** by email, Slack incoming webhook, and a signed generic webhook.
  - **The Alerts page:** rules, what is firing, history, and a rule editor with a 24 h preview.
  - **Issue events:** new issue, regressed issue.
- **Out (deliberately):**
  - anomaly detection, multi-condition rules, escalation, on-call, acknowledgements
  - PagerDuty, Opsgenie or SMS (the generic webhook covers them)
  - status pages, scheduled silences (only "mute until"), sub-minute evaluation
  - free-form query text
  - dashboards — a rule's preview chart is not a saved chart
- **Later, only if measured:** rollup tables, query coalescing, Alertmanager-style grouping and
  digests, repeat notifications, uptime incidents as a source (once `specs/uptime.md` step 4
  ships).

## Patterns we follow rather than invent

| Concern | Pattern | What we take |
| --- | --- | --- |
| Rule semantics | **Prometheus alerting rules** | A rule is a query, a condition, `for` and `keep_firing_for`. Each result series is an *alert instance* with state `inactive → pending → firing`. Only firing and resolved are notified. |
| No data and errors | **Grafana alerting** | Per-rule `no_data_state`: `inactive` (OK), `firing` (Alerting) or `keep_last` (KeepLast). Query errors keep the last state and mark the rule's *health* `err` (Prometheus `health` + `lastError`). |
| Evaluation loop | **Prometheus rule groups** | Evaluate at a fixed interval, at an aligned timestamp minus a lag, over `[at − window, at)`. A missed tick is skipped, never replayed. |
| Scheduling | **Laravel scheduler + queue** | One `alerts:evaluate` command per minute dispatches a queued job per due rule. Due-ness is `next_evaluation_at` in the app DB. |
| Delivery | **Laravel Notifications** (queued) | Built-in `mail` channel. Small custom `SlackWebhookChannel` / `WebhookChannel` using the `Http` facade. Retries and backoff are the queue's. |
| Webhook signing | **Standard Webhooks** (standardwebhooks.com) | Headers `webhook-id`, `webhook-timestamp`, `webhook-signature: v1,<base64 HMAC-SHA256 of "id.timestamp.body">`; secret `whsec_<base64>`. Receivers can verify with any off-the-shelf Standard Webhooks library. |
| Slack | **Slack incoming webhooks** | `POST {"text": …}` to the URL Slack gives. No bot, no OAuth. |

## Why not at the writers

Hooking `LogWriter`, `SpanWriter` or `MetricWriter` looks natural and is wrong:

- **The writers run inside the ingest request.** Matching every row against every rule costs rows ×
  rules on the hottest path.
- **A writer sees one batch from one client.** Windows across clients and Octane workers would need
  a second aggregation engine next to ClickHouse.
- **Absence cannot be seen at write time.** No writer runs for a service that went silent.

So no alerting code is added to the ingest path. `tests/Unit/Alerting/AlertingStaysOffTheIngestPathTest.php`
(an `arch()` test) pins it: nothing in `App\Services\Ingest` or `App\Http\Controllers\Api` may use
`App\Services\Alerting`, `EvaluateAlertRule` or `App\Notifications`.

## Data model (app database)

The app DB is PostgreSQL in production and SQLite in tests. Everything below is plain Eloquent and
portable to both.

### `alert_rules`

| Column | Notes |
| --- | --- |
| `team_id` | Scoping; route binding is team-scoped like `{project}` |
| `name` | |
| `source` | `logs` \| `traces` \| `metrics` — key into `config('alerting.sources')` |
| `project_ids` | json; re-validated against the team's projects on save and on every evaluation |
| `query` | json, the source's structured query: `{filters, aggregation, field?, group_by?}`; never SQL |
| `condition` | `>` `>=` `<` `<=` |
| `threshold` | decimal |
| `window_seconds` | 60 … 86,400 |
| `interval_seconds` | 60, 300 or 900; a hard minimum of 60 protects ClickHouse |
| `for_seconds` | 0 … 3,600 (Prometheus `for`) |
| `keep_firing_for_seconds` | 0 … 3,600 (Prometheus `keep_firing_for`) |
| `no_data_state` | `inactive` \| `firing` \| `keep_last` (Grafana) |
| `severity` | `info` \| `warning` \| `critical`; carried into notifications only |
| `enabled` | |
| `muted_until` | nullable; evaluates and records, sends nothing |
| `next_evaluation_at` | indexed; the scheduler's queue |
| `last_evaluated_at`, `health` (`unknown` \| `ok` \| `err`), `last_error` | |
| `created_by` | |

### `alert_instances` — Prometheus' "active alerts", one row per series

`alert_rule_id`, `labels` (json, e.g. `{"service.name": "checkout"}`, `{}` when ungrouped),
`labels_hash` (unique per rule), `state` (`inactive` \| `pending` \| `firing`), `value`,
`active_at` (became pending), `fired_at`, `keep_firing_since` (Prometheus' `KeepFiringSince`: when a
firing instance's condition first turned false; null otherwise), `last_seen_with_data_at`,
`resolved_at`, `updated_at`.

- An instance that is `inactive` and has had no data for 24 h is deleted.
- A known instance with no value this evaluation is the no-data case, so absence alerting falls out
  of the table with no extra mechanism.
- A rule producing more than `alerting.max_instances` (100) series keeps the 100 most extreme
  (highest for `>`/`>=`, lowest for `<`/`<=`). Series that had data but fell outside the cap keep
  their state; they are **not** treated as no data.
- Only a known instance can be missing, so a rule with no instance yet never fires on no data.

### `alert_events` — history, append-only

`team_id`, `alert_rule_id` (nullable for issue events), `alert_instance_id` (nullable), `type`
(`firing` \| `resolved` \| `issue_new` \| `issue_regressed` \| `rule_error`), `labels`, `value`,
`threshold`, `occurred_at`, `context` (json: deep links into the explorers).

- Unique `dedupe_key`: `rule:{id}:{labels_hash}:{type}:{evaluated_at}` for rule events, and
  `issue:{project}:{fingerprint}:new` / `issue:{project}:{fingerprint}:regressed:{resolved_at unix}`
  for issue events. A retried job writes nothing
  twice and notifies nothing twice.
- Pruned after 90 days.

### `notification_channels`

`team_id`, `type` (`email` \| `slack` \| `webhook`), `name`, `config` (encrypted cast: addresses or
`members`, URL, `whsec_` secret), `enabled`, `issue_events` (default false), `last_failed_at`,
`last_error`.

The pivot `alert_rule_notification_channel` links rules to channels. Issue events go to the team's
channels flagged `issue_events` (a boolean on the channel). This is kept simple deliberately: no
routing tree.

## Evaluation

### `alerts:evaluate`, every minute

```php
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping(2)
    ->onOneServer()->runInBackground();
```

1. Select enabled rules with `next_evaluation_at <= now()`, ordered by it, in chunks.
2. For each, set `next_evaluation_at` to the next aligned slot **after now** (a 10-minute outage
   evaluates once, not ten times), then dispatch `EvaluateAlertRule($ruleId, $evaluatedAt)`.
3. Write the heartbeat: cache key `alerting.heartbeat`.

There is exactly one scheduler container and `withoutOverlapping`, so no row locking is needed. The
2-minute lock expiry means a killed run cannot silence alerting for the default 24 h.

### `EvaluateAlertRule`

- `ShouldQueue`, `ShouldBeUnique` on `{ruleId}:{evaluatedAt}`, on queue `alerts`. It runs on the
  default Horizon supervisor until load says otherwise.
- `$evaluatedAt = align(now − alerting.lag, interval)`, with lag 60 s for client-side batching.
  The window is `[evaluatedAt − window, evaluatedAt)`, closed-open.
- Timeout chain: ClickHouse `max_execution_time` (the client's configured timeout) < job
  `$timeout` (20 s) < queue `retry_after`. `$tries = 1`: the next tick is the retry. A job older
  than the rule's `last_evaluated_at` does nothing.
- Calls the rule's `AlertSource`, then `AlertStateMachine` per instance. Writes instances, then
  `alert_events` for `firing` / `resolved` (unique key guards retries), then notifies
  (`afterCommit`).

### `AlertSource`

```php
interface AlertSource
{
    /** @return list<array{labels: array<string,string>, value: float}> one entry per series with data */
    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Bucketed series for the editor preview. */
    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array;

    /** Validation rules for `query`, and what the editor offers (aggregations, group-by keys). */
    public function rules(): array;
}
```

Registered in `config/alerting.php`. Adding an entity means adding a class. A source that cannot
read throws `AlertSourceUnavailable` — never `[]`, which would read as no data. Every source builds SQL
through its entity's existing query service, so the ProjectId predicate comes from that service's
base conditions, user filters append, and values are `{name:Type}` parameters.

| Source | Aggregations | Reads |
| --- | --- | --- |
| `metrics` | the metric's own reading (gauge value; counter as rate), `avg`/`max`/`min`/`last` over the window; histograms `p50`/`p95`/`p99` only | `MetricQuery::series()` with `MetricFilters` `interval`/`maxGroups`/`exclusiveTo` (R14–R16) |
| `logs` | `count`, `rate` (per minute), `ratio` (filtered / all lines of the same projects and services) | `LogQuery::alertValues()` on `LogQuery`'s base conditions (R4); body search per R5. An ungrouped empty window counts 0, not no data |
| `traces` | `count`, `error_ratio`, `p50`/`p95`/`p99` of `Duration` (ms) | `TraceQuery::alertValues()`: `otel_traces` spans with an R4-shaped window, `StatusCode = 'Error'` (R10). No `trace_index` nomination, so the window is capped at 1 h — in the form and again at evaluation; the preview is not capped |

### `AlertStateMachine` (pure)

Prometheus semantics, checked against Prometheus' `rules/alerting.go`:

- **Condition true:**
  - `inactive` → `pending`, with `active_at = evaluatedAt`
  - `pending` → `firing` once `evaluatedAt − active_at ≥ for` (immediately when `for = 0`). This
    emits **firing**.
- **Condition false:**
  - `pending` → `inactive`, silent
  - `firing` → `inactive` once false for `keep_firing_for` (immediately when 0). This emits
    **resolved**.
- **No data** for a known instance: `no_data_state` decides whether it is treated as true
  (`firing`), false (`inactive`) or skipped (`keep_last`).
- **Query error:** no instance changes. The rule gets `health = err` and `last_error`. On the
  transition `ok → err` one `rule_error` event is notified, and `err → ok` notifies nothing.

## Issue events

`alerts:evaluate` dispatches one queued `ScanIssueAlerts` per minute (queue `alerts`, unique per
minute, one try), and only while some team has an enabled channel flagged `issue_events`. The job
runs `IssueAlertScan` per subscribed team, nominating from `issue_hourly`'s current and previous
hour (R18) with a 3-minute overlapping lookback and reading the headline from `issue_summary` (R17);
the SQL is `IssueQuery::alertCandidates()`.

- **Issue new:** `FirstSeen` within the lookback, unless the issue is already ignored. Dedupe key
  `issue:{project}:{fingerprint}:new`.
- **Issue regressed:** a resolved issue whose `LastIngested` is after `resolved_at`
  (`IssueState`'s own rule). Dedupe key `issue:{project}:{fingerprint}:regressed:{resolved_at
  unix}`, so a second resolve-and-regress notifies again.
- At most 50 new-issue events per team per scan, oldest first; the rest are skipped (no summary
  event) and still listed on the Issues page.

The `dedupe_key` makes overlapping scans harmless. Known limitations: "new" reads `FirstSeen`, the
client's clock, so an event stamped more than ~3 minutes in the past never raises `issue_new`; a
regression is caught only if a scan runs within 3 minutes of the event arriving (a longer scheduler
outage leaves it visible in the UI and alerted on the issue's next event).

## Notifications

- **`AlertNotification`**: a queued Laravel `Notification`, with `$tries = 10` and an exponential
  `backoff()` capped at 1 h. It is sent through `Notification::route()` per channel:
  - `mail` → team members or listed addresses
  - `slack` → `SlackWebhookChannel`
  - `webhook` → `WebhookChannel`
- **Failure recording.** A permanently failed send records `last_failed_at` / `last_error` on the
  channel, shown in the UI.
- **Webhook payload (Standard Webhooks):** `{type, timestamp, data: {event_id, rule, labels, value,
  threshold, condition, state, severity, window, error, issue, links}}`; `type` is `alert.{event type}`;
  `issue` is `{project, fingerprint, type, message, service, events, first_seen, resolved_at}` for
  issue events and `null` for rule events;
  `webhook-id` is `msg_{event id}`, stable across retries.
- **Permanent failures** (`UnsafeWebhookUrl`, a broken channel config) fail at once via the
  `FailWithoutRetry` middleware — not `FailOnException`, whose closure cannot be serialised with a
  queued notification. Queued notifications carry ids only; URLs and secrets are read at send time.
- **SSRF guard — `SafeWebhookUrl`** (required for Slack and webhook):
  - `https` only outside `local`/`testing`, default ports only, no userinfo, canonical IP literals
    only, no redirects;
  - the host is resolved at send time, and loopback, private, link-local and metadata addresses
    are refused (IPv4 and IPv6);
  - the request is pinned to the resolved IP (`CURLOPT_RESOLVE`) so a DNS rebind cannot slip
    through;
  - Slack URLs must be on `hooks.slack.com`.
- **Mute.** A muted rule, or a disabled channel, records events and sends nothing.
- Every notification links into the matching explorer for the evaluated window.

## Reliability

- **Heartbeat** — `GET /health/alerting` (`health.alerting`) returns `200 {"status": "ok", "lag": s}`,
  `503` (`stale` / `missing`) when the heartbeat is older than 3 minutes or absent, and `200
  {"status": "disabled"}` when alerting is off. It has no auth and exposes no data. Point an external uptime check at it.
- **Scheduler healthcheck** — the scheduler role in `docker-healthcheck.sh` also runs
  `alerts:heartbeat-check`.
- **Queue** — `config/horizon.php` always appends `alerts` to the supervisor's queues.
- **Visible lag** — the Alerts page shows a banner when rules are overdue by more than one interval.
- **Rule health** — `health`, `last_error` and `last_evaluated_at` are shown per rule.

## Plans, MCP, UI

- **Plans:** `config/plans.php` has a soft `alert_rules_per_team` (Free: 10, enabled or not), read
  through `PlanLimits::alertRulesPerTeam()` and counted live by `PlanUsage`. Rules past it are saved
  and evaluated normally.
- **MCP:** read-only `list-alerts` (firing/pending instances and the event history; window default
  24 h, capped at 7 days; `limit` ≤ 200), reading only the app database and scoped to the team by
  `ResolvesScope`.
- **UI:**
  - **Alerts** under Triage in `AppSidebar.vue`, with a deferred firing-count badge;
  - `alerts.index`: firing / pending instances, rules with health, history;
  - `alerts.history`: the team's events, newest first (a tab beside the rules);
  - the rule editor: source, the source's filters, aggregation, group-by, condition,
    window/interval/for, channels, plus a 24 h preview through `ChartCanvas.vue` with the threshold
    line. The preview's step is `max(window, chart bucket)`; a log/trace `count` is rescaled by
    `window / step` (`scaled: true`) so it is comparable with the threshold;
  - channels on the team settings page (`teams.notification-channels.*`, owners and admins), with
    "send test" (synchronous, throttled 10/min); secrets leave the server only masked
    (`NotificationChannel::toSummary()`);
  - new components go into the styleguide;
  - state colours are data: firing is `--severity-error`, pending is `--severity-warn`, inactive is
    neutral.

## Configuration — `config/alerting.php`

`enabled`, `lag` (60), `max_instances` (100), `sources` (map), `events_retention_days` (90).

## Build order

1. ✅ Spec.
2. ✅ Model and core:
   - migrations, models, factories, `config/alerting.php`;
   - `AlertStateMachine` with full unit tests.
3. ✅ Evaluation:
   - `AlertSource`, `MetricAlertSource`, `LogAlertSource`, `TraceAlertSource` (live tests against
     ClickHouse);
   - `alerts:evaluate`, `EvaluateAlertRule`, heartbeat, `/health/alerting`, scheduler healthcheck.
4. ✅ Notifications:
   - `AlertNotification`, `SlackWebhookChannel`, `WebhookChannel` (Standard Webhooks),
     `SafeWebhookUrl`;
   - failure recording.
5. ✅ UI: Alerts page, rule editor with preview, channels, sidebar badge, styleguide.
6. ✅ Docs page, `CLAUDE.md` scope line and architecture row, `.ai/rules/alerting.md`; issue events,
   MCP `list-alerts`, plan number.

## Relation to a query language

A rule's `query` JSON is the structured form a future query language would compile into. Rules
store the structured form; query text would only ever be another way to author it.
