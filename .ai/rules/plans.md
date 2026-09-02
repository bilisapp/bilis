---
paths:
  - config/plans.php
  - 'app/Services/Plans/**'
---

# Plans

## Every hosted plan limit is soft, and nothing may enforce one
`config/plans.php` publishes what the Free tier on bilis.app allows: projects and members per team, events per UTC day, and the warn threshold. None of it is a gate. No ingest path reads these values — a log record or a span is never refused, dropped, sampled or delayed because a team is over an allowance — and no button in the app disables on one. Going over turns a meter and produces one sentence pointing at `/contact?topic=upgrade`; that is the entire enforcement story, deliberately. Deleting telemetry to make a point about a quota loses exactly the data someone is about to need, and it breaks the "ingest never returns 400 / never blame the client" invariant in spirit if not in letter.

The one real ceiling is the per-key ingest rate limit, and it is not ours: it lives in `config/security.php`, is enforced by `throttle:ingest`, and answers with a retryable 429 plus `Retry-After` rather than rejecting a payload.

## PlanLimits is the only reader, and two of the six numbers are not stored here
The pricing page, the dashboard card, the team settings page, the project modal and the docs trip-wire test all go through `App\Services\Plans\PlanLimits`. Never `config('plans...')` at a call site and never a literal in a Blade view or a Vue component — the whole point of one reader is that the published number and the measured number cannot disagree.

Retention comes from `legal.log_retention_days` and requests-per-minute from `security.ingest_rate_limit`. Both are already promised (the terms and privacy pages render the first) or already enforced (the limiter reads the second), and restating either in `config/plans.php` is how a published number goes stale against the behaviour it describes. `PlanLimits::retentionDays()` / `requestsPerMinute()` exist precisely so those two travel with the other four.

`resources/docs/reference/limits-and-behavior.md` hardcodes the numbers, because docs markdown is static. `tests/Feature/DocsTest.php` asserts the page contains what `PlanLimits` currently returns, so changing a `BILIS_PLAN_FREE_*` default without editing the doc trips a test rather than shipping a lie.

## PlanUsage counts events the way LogQuery reads logs
`PlanUsage::forTeam()` counts projects and members live from SQLite (they are cheap, and a stale count on the page someone just created a project from reads as a bug) and today's events from ClickHouse. Both event counts are plain SCHEMA.md R4 range reads — `ProjectId IN {projectIds:Array(String)}` and a closed `Timestamp` window, no bucket expression, every value a `{name:Type}` parameter. An empty project list short-circuits to zeroes with no HTTP call at all; `DashboardTest` pins that.

The count is cached 300s under a key carrying **today's UTC date**, so yesterday's total cannot survive midnight. A `ClickHouseException::isOverload()` yields `unavailable: true`, is reported, and is never cached — an outage must not freeze the card for the whole window. Any other ClickHouse error is rethrown.
