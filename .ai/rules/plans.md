---
paths:
  - config/plans.php
  - 'app/Services/Plans/**'
---

# Plans

## Every hosted plan limit is soft, and nothing may enforce one
`config/plans.php` publishes what the Free tier on bilis.app allows: projects and members per team, events per UTC day, metric data points per UTC day, and the warn threshold. None of it is a gate. No ingest path reads these values — a log record, a span or a metric data point is never refused, dropped, sampled or delayed because a team is over an allowance — and no button in the app disables on one. Going over turns a meter and produces one sentence pointing at `/contact?topic=upgrade`; that is the entire enforcement story, deliberately. Deleting telemetry to make a point about a quota loses exactly the data someone is about to need, and it breaks the "ingest never returns 400 / never blame the client" invariant in spirit if not in letter.

The one real ceiling is the per-key ingest rate limit, and it is not ours: it lives in `config/security.php`, is enforced by `throttle:ingest`, and answers with a retryable 429 plus `Retry-After` rather than rejecting a payload.

## PlanLimits is the only reader, and two of the seven numbers are not stored here
The pricing page, the dashboard card, the team settings page, the project modal and the docs trip-wire test all go through `App\Services\Plans\PlanLimits`. Never `config('plans...')` at a call site and never a literal in a Blade view or a Vue component — the whole point of one reader is that the published number and the measured number cannot disagree.

Retention comes from `legal.log_retention_days` and requests-per-minute from `security.ingest_rate_limit`. Both are already promised (the terms and privacy pages render the first) or already enforced (the limiter reads the second), and restating either in `config/plans.php` is how a published number goes stale against the behaviour it describes. `PlanLimits::retentionDays()` / `requestsPerMinute()` exist precisely so those two travel with the other five.

`resources/docs/reference/limits-and-behavior.md` hardcodes the numbers, because docs markdown is static. `tests/Feature/DocsTest.php` asserts the page contains what `PlanLimits` currently returns, so changing a `BILIS_PLAN_FREE_*` default without editing the doc trips a test rather than shipping a lie.

## PlanUsage counts events the way LogQuery reads logs
`PlanUsage::forTeam()` counts projects and members live from SQLite (they are cheap, and a stale count on the page someone just created a project from reads as a bug) and today's events from ClickHouse. Both event counts are plain SCHEMA.md R4 range reads — `ProjectId IN {projectIds:Array(String)}` and a closed `Timestamp` window, no bucket expression, every value a `{name:Type}` parameter. An empty project list short-circuits to zeroes with no HTTP call at all; `DashboardTest` pins that.

The count is cached 300s under a key carrying **today's UTC date**, so yesterday's total cannot survive midnight. A `ClickHouseException::isOverload()` yields `unavailable: true`, is reported, and is never cached — an outage must not freeze the card for the whole window. Any other ClickHouse error is rethrown.

## Metric data points are their own meter, never events
`metric_points_per_day` (default 1,000,000) counts rows across the five `otel_metrics_*` tables — one gauge reading, one counter value, one histogram point however many buckets it carries. It is never folded into `events`: a service exporting every ten seconds writes thousands of points a day without anything happening, and that must not spend a log budget.

`PlanUsage::metricPoints()` is **one** statement — `SELECT sum(c) FROM (SELECT count() AS c FROM otel_metrics_gauge WHERE … UNION ALL …)` — each branch `ProjectId IN {projectIds:Array(String)}` plus a closed `TimeUnix` window bound as `{from:DateTime('UTC')}` / `{to:DateTime('UTC')}` (whole seconds, SCHEMA.md R14; never the `DateTime64(9)` the log/span count uses). It has its own cache key (`plans.metric-points.{Y-m-d}.{sha1(ids)}`) and its own `unavailable` flag, so an overload on one reading never blanks the other meter. Same rules otherwise: no projects → no HTTP call, overload → reported and never cached, anything else rethrown. In `PlanUsageCard.vue` either reading being unavailable shows its own note and suppresses the over/warn sentence, because a verdict drawn from half the meters would be a guess.
