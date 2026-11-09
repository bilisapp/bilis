---
paths:
  - 'app/Services/Alerting/**'
  - app/Jobs/EvaluateAlertRule.php
  - app/Jobs/ScanIssueAlerts.php
  - 'app/Notifications/**'
  - 'app/Http/Controllers/Alerts*.php'
  - 'resources/js/pages/alerts/**'
  - 'app/Services/Alerting/Templates/**'
  - app/Jobs/ProvisionTeamAlerts.php
  - config/alerting_templates.php
---

# Alerting

Spec: `specs/alerting.md`. Rules are evaluated by **querying** ClickHouse on a schedule, never at ingest: `LogWriter`/`SpanWriter`/`MetricWriter` see one batch from one client, run on the hottest path, and cannot see a service that went silent. Do not add a hook there.

## The path, end to end
Scheduler → `alerts:evaluate` (every minute, `withoutOverlapping(2)`, `onOneServer`, `runInBackground`) picks enabled rules with `next_evaluation_at <= now`, moves each to the next aligned slot **strictly after now** (`EvaluationClock::nextSlotAfter` — a ten-minute outage evaluates once, never replays) and dispatches `EvaluateAlertRule($ruleId, $evaluatedAt)`, then writes the `alerting.heartbeat` cache key. `evaluatedAt = align(now − alerting.lag, interval)`; the window is `[evaluatedAt − window, evaluatedAt)`. The job calls the rule's `AlertSource`, runs `AlertStateMachine::next()` per instance, writes instances and events in one transaction, and hands only newly-created events to `AlertNotifier` after commit. The 2-minute overlap lock is deliberate: a killed run must not silence alerting for the default 24 h.

The job is `ShouldBeUnique` on `{ruleId}:{evaluatedAt}`, `$tries = 1`, `$timeout = 20` (inside `retry_after`, above the ClickHouse client timeout). The next tick is the retry, as in Prometheus. A job older than the rule's `last_evaluated_at` returns without touching anything — a delayed job must never move instances back in time.

## Horizon must consume the `alerts` queue
`EvaluateAlertRule` and `AlertNotification` both run on `alerts`. `config/horizon.php` **appends** `alerts` to whatever `HORIZON_QUEUES` names, so overriding the env var cannot silently stop alerting. `AlertsEvaluateCommandTest` pins it; keep the append if you touch the supervisor block.

## The source contract (`Sources\AlertSource`)
Register a class in `config('alerting.sources')`; that map is the only extension point (an uptime source would be one more class).

- **A source that cannot read throws `AlertSourceUnavailable`; it never returns `[]`.** An empty list is "no data", which the state machine may fire or resolve on. The query services answer an overloaded ClickHouse with `null` (so viewers still render) — `ReadsAlertValues::read()` turns that, and every `ClickHouseException`, into `AlertSourceUnavailable`. A malformed stored `query` is also unavailable, re-validated against `rules()` before every read. The job then keeps every instance as it was, sets `health = err`, and records one `rule_error` event on the `ok → err` edge only.
- **SQL lives in the entity's query service**, never in the source: `LogQuery::alertValues()`, `TraceQuery::alertValues()`, `MetricQuery::series()` (via `MetricFilters` `interval`/`maxGroups`/`exclusiveTo`). That is how the ProjectId predicate, R4/R5/R10/R14–R16 and `{name:Type}` binding are inherited. `AlertSourcesTest` asserts every user value is a parameter.
- **The window is closed-open `[from, to)`**, unlike the viewers' closed R4 window, so adjacent evaluations never count a row twice. Each service has an explicit `exclusiveTo` path; do not reuse a viewer's closed read.
- Project ids are re-intersected with the rule's team's projects on **every** evaluation (`EvaluateAlertRule::projectIds()`); none left → `rule_error`, no read.
- Ungrouped log/trace `count` always returns one row, so an empty window is `0`, not no data. A ratio or percentile over nothing is NaN → null → no instance (no data). A metric never reported is `[]` (no data). Percentile on a gauge/counter, or `avg` on a histogram, is refused as unavailable.

## Trace rules read at most a one-hour window
`TraceAlertSource` reads `otel_traces` spans directly (R4-shaped `spanConditions()`, no `trace_index` nomination), so it is a scan of the window: `MAX_WINDOW_SECONDS = 3600`, enforced in `SaveAlertRuleRequest` and again in `evaluate()` (throws unavailable). The editor preview's 24 h read is not bound by it (`preview()` reads in buckets of `max(window, step)`), but `PreviewAlertRuleRequest` validates `window_seconds` exactly like a save, so a trace window the rule could never be saved with is reported in the editor before saving, not charted.

## Prometheus semantics, and why `keep_firing_since` exists
`AlertStateMachine` is pure (no DB, no clock) and mirrors Prometheus `rules/alerting.go`: true → inactive→pending→firing after `for` (same evaluation when `for = 0`); false → pending→inactive silently, firing→inactive once false for `keep_firing_for`. Only `firing` and `resolved` are events. `keep_firing_since` (Prometheus `KeepFiringSince`) records when a firing instance's condition first turned false; without it `keep_firing_for` would have to be measured from `fired_at` or restart every evaluation. A true condition clears it. Elapsed time is compared in whole seconds and "exactly equal" counts as elapsed. Change semantics only with a unit test in `AlertStateMachineTest` and a reason from Prometheus or Grafana.

## No data vs. capped series
No-data (Grafana `no_data_state`: `inactive` / `firing` / `keep_last`) applies only to a **known instance absent from a successful reading**. A rule with no instance yet cannot fire on no data — there is nothing to be absent. A rule with more than `alerting.max_instances` (100) series keeps the most extreme (highest for `>`/`>=`, lowest for `<`/`<=`); series that **had data but fell outside the cap are left exactly as they were**, never fed to the no-data path, or every cap overflow would resolve or fire them. The cap happens **in the source**, so it must be condition-aware there: `LogQuery`/`TraceQuery::alertValues($lowestFirst)` order the grouped read by value (`ASC` for `<`) and are asked for `max_instances + 1` rows; `MetricAlertSource` asks `MetricQuery::series()` for every group (`maxGroups: SERIES_LIMIT` — the explorer's builder keeps the *heaviest* groups, which is right for a chart and wrong for an alert) and ranks the reduced values itself. Whatever the source dropped, it says so with `AlertReading::$truncated` (a metric read that hit R16's 500-series cap counts too); on a truncated reading the job skips the no-data path for **every** absent instance, because it cannot tell "capped out" from "silent". Inactive instances with no data for 24 h are deleted.

**Writes recheck under a lock.** `handle()`'s "older than `last_evaluated_at`" check only saves a read; `recordEvaluation()`/`recordError()` re-read the rule with `lockForUpdate()` inside the transaction and drop the write if the rule is gone, disabled or already evaluated past this tick. `failed()` uses the same guard inclusive of this tick (`>=`), so an old job's timeout never turns a healthy rule `err`, rewinds `last_evaluated_at` or sends a `rule_error`.

## Dedupe keys; notify only newly-created events, after commit
`alert_events.dedupe_key` is unique: `rule:{id}:{labels_hash}:{type}:{evaluatedAt}` (issue events: `issue:{project}:{fingerprint}:new` / `:regressed:{resolvedAtUnix}`, below). Events are written with `insertOrIgnore`, and only ids that were actually inserted reach `AlertNotifier`, inside `DB::afterCommit`. A retried or duplicated evaluation therefore records nothing twice and notifies nothing twice, and a rolled-back transaction notifies nothing. A muted rule records events and sends nothing. `labels_hash` comes from `Labels::hash()` (key-order independent; `{}` for ungrouped). An event freezes the rule's `threshold`, `condition` and `severity` when recorded; render it through `AlertEvent::judgedCondition()`/`judgedSeverity()` (snapshot, falling back to the rule for older rows), never `$event->rule->condition`, so an edit made while a notification waits in the queue cannot invert what it says.

## Issue events ride the same tables
`alerts:evaluate` dispatches one `ScanIssueAlerts` per minute **per subscribed team** (queue `alerts`, `ShouldBeUnique` on team + minute, `$tries = 1`, `$timeout = 50` as a per-team budget) — only teams with an enabled channel flagged `issue_events`, so a team without one costs no ClickHouse read. The job calls `IssueAlertScan::scanTeamId()` — **all issue-table SQL stays in `IssueQuery`**, in two reads with separate caps so regressions can never crowd out new issues: `newIssueCandidates()` nominates from `issue_hourly`'s current and previous hour (R18) and re-aggregates the headline on `issue_summary` (R17), keeping `FirstSeen` inside the lookback; `regressionCandidates()` looks up the team's resolved issues (app DB, one query) by key on `issue_summary`, keeping `LastIngested` inside the lookback. Never nominate regressions from `issue_hourly`: it is keyed by the client's timestamp, so a late-flushed old event would be missed while `IssueState` calls the issue regressed.

- **`issue_new`:** `FirstSeen` within the last `LOOKBACK_SECONDS` (180), unless the issue is already ignored. Dedupe key `issue:{projectId}:{fingerprint}:new`.
- **`issue_regressed`:** a resolved issue whose `LastIngested` is after `resolved_at` (`IssueState`'s own rule). Dedupe key `issue:{projectId}:{fingerprint}:regressed:{resolvedAtUnix}`, so resolve → regress → resolve → regress notifies twice.
- The 180 s lookback overlaps three ticks on purpose (a late tick or a slow async insert loses nothing); the dedupe key makes the overlap harmless.
- At most `MAX_NEW_PER_TEAM` (50) `issue_new` events per team per scan, oldest first; the rest are skipped with no summary event (a later overlapping scan may still record them). The Issues page lists every one regardless.
- An overloaded read (`null`) or a failure for one team is reported and that team skipped; the next tick overlaps.
- These events have no rule (`alert_rule_id` null) and go to the team's enabled channels flagged `issue_events`, never to a rule's channels. `AlertMessage` titles them `[NEW ISSUE] …` / `[REGRESSED ISSUE] …`, links them to the issue page, and the webhook carries `data.issue` (`project`, `fingerprint`, `type`, `message`, `service`, `events`, `first_seen`, `resolved_at`) — `null` for rule events.

Known limitations, accepted:
- "New" reads `FirstSeen`, which is the **client's** clock. An event stamped more than ~3 min in the past never raises `issue_new`.
- A regression is caught only if a scan runs within 3 min of the event arriving. With the scheduler down longer, the regression shows in the UI and is alerted on the issue's next event.

## `except()` on an Eloquent collection filters by model key
`Eloquent\Collection::except($keys)` removes models whose **primary key** is in `$keys` — it ignores the array keys you set with `keyBy('labels_hash')`. Using it to drop "seen" hashes kept every instance, so every known series went through the no-data path. Use `reject(fn ($i) => isset($seen[$i->labels_hash]))` (as the job does), or `->toBase()->except()` if you really mean array keys.

## Notifications carry ids, never secrets
`AlertNotifier` sends one queued `AlertNotification($channelId, $channelType, $eventId)` per enabled channel via `Notification::route()`, so one failing channel retries alone. The Slack/webhook URL and `whsec_` secret are credentials in the encrypted `config` cast; they are read fresh at send time, never serialised into the queue payload. `shouldSend()` makes a pruned event or a deleted/disabled channel a no-op. Ten tries, backoff 30 s doubling to 1 h; `failed()` writes `last_failed_at`/`last_error` using `AlertNotification::describe()`, which only repeats our own exception messages — never a URL. `ClearChannelFailure` clears it after a success.

## `FailWithoutRetry`, not `FailOnException`
An `UnsafeWebhookUrl` or a `DeliveryFailed` with `permanent` will not heal by waiting, so `Notifications\Middleware\FailWithoutRetry` fails the job at once. Laravel's `FailOnException` would do the same but holds a closure, and a queued notification's middleware is serialised with the job — it cannot be used here.

## `SafeWebhookUrl` — the SSRF guard for any customer URL
The box also runs ClickHouse on localhost. `SafeWebhookUrl::validate()` (form) and `resolve()` (send time) refuse non-https (http only in `local`/`testing`), userinfo, non-default ports, non-canonical IPv4 (`2130706433`, `0x7f.1`, `127.1`), `localhost`, and any host whose A/AAAA records include a private, loopback, link-local, CGNAT, metadata, documentation, multicast or IPv4-embedding IPv6 address. Slack must be exactly `hooks.slack.com`.

- **Always send through `ResolvedWebhookTarget::pendingRequest()` to `$target->url`** — the rebuilt URL, pinned with `CURLOPT_RESOLVE` to the checked IP, no redirects, 5 s timeouts. Posting to the channel's raw URL, or a new `Http::` call, reopens DNS rebinding.
- **Never route these requests through an HTTP proxy** (`HTTP(S)_PROXY`, Guzzle `proxy`): the proxy resolves the host itself and the pin is bypassed.
- **Reuse it for any new outbound fetch of a customer-supplied URL** rather than writing another guard (`Tools\PageFetcher` predates it).

Webhooks are signed per Standard Webhooks (`StandardWebhookSigner`, tested against the official vector); `webhook-id` is `msg_{eventId}`, stable across retries so receivers can de-duplicate. Do not invent another signature scheme.

## Secrets leave the server only masked
Channel props go through `NotificationChannel::toSummary()`: host only (`hooks.slack.com (URL hidden)`), `hasSecret` boolean, addresses for email. A generated secret is flashed exactly once on create. An edit with a blank URL/secret keeps the stored value. Never pass a `NotificationChannel` model or its `config` to Inertia.

## Smaller settled points
- Channel CRUD routes are `teams.notification-channels.*` (owners/admins only; `test` is throttled 10/min and sends synchronously so the card can show the error). Any team member may write, mute and toggle rules.
- `{alertRule}` / `{notificationChannel}` bindings resolve through the team in the route (`AppServiceProvider`); another team's id is a 404.
- `/health/alerting` (`health.alerting`) strips the `web` middleware group, has no auth and returns only status + lag; 503 when the heartbeat is older than 180 s. `alerts:heartbeat-check` is the same check for the scheduler container's healthcheck. Both pass when `alerting.enabled` is false. Because of the route, `health` is now a reserved team slug (`TeamName::routesPrefixes()`).
- The editor preview reads 24 h with step `max(window, TimeBuckets::interval(...))`; a log/trace `count` is rescaled by `window / step` (`scaled: true`) so the line is comparable with the threshold. Rates, ratios, percentiles and metrics are not rescaled.
- `AlertBadge` counts firing instances (muted rules included — muting silences delivery, not the truth), deferred in the `nav` group and cached 60 s.
- State colours are data: firing `--severity-error`, pending `--severity-warn`, inactive neutral.
- Alert history (`AlertEvent`, `Prunable`) is dropped after `alerting.events_retention_days` by the daily `model:prune`.
- The MCP `list-alerts` tool is read-only (firing/pending instances and recent events from the app DB) and scoped by `ResolvesScope`, like every other tool (mcp.md). The Free plan's `alert_rules_per_team` number (10) is soft like every plan number (plans.md): measured and shown, never a reason to refuse saving or evaluating a rule.

## Defaults and provisioning
Spec: `specs/alerting-defaults.md`. Every team, existing and new, gets a "Team email" channel and the catalogue's rules for the data it sends — no opt-in. `alerts:provision` (every 15 min, `withoutOverlapping`, `onOneServer`) and `CreateTeam` (`afterCommit`) dispatch `ProvisionTeamAlerts` (queue `alerts`, unique per team, `$tries = 1` — the next run is the retry) → `AlertProvisioner::provision()`.

- **It is a reconciler, not an installer.** Idempotent and re-run forever. A missing template rule is created (when its data is detected); an existing rule that is `enabled`, has `customized_at` null and an older `template_version` is upgraded (only the template's own fields — never `enabled`, channels, mute or state); anything else is left alone. The rule row (`template_key`, `template_version`, `customized_at`, unique `(team_id, template_key)`) and `teams.alerting_provisioned_at` are all the state there is — do not add a table.
- **The channel is created once.** Only while `alerting_provisioned_at` is null (claimed by a conditional update). A team that deletes "Team email" never gets it back.
- **Deleting a template rule turns it off; never delete the row.** `AlertsController::destroy` sets `enabled = false` for `isTemplate()` rules. A missing row means "not installed yet" and the next run recreates it; a turned-off row is how the reconciler knows to stay away. The UI says "Turn off", not "Delete". Any new "remove" path (MCP, API, bulk action) must do the same.
- **`customized_at` is stamped only when the definition really changes**: `AlertsController::DEFINITION_FIELDS` (name, source, project_ids, query, condition, threshold, timings, no_data_state, severity), compared canonically. Channels, mute and enable/disable say where or whether an alert goes, not what it is — they never customise. An unchanged save is not a customisation.
- **Bumping a template's `version` is the only way to change installed rules.** Editing `rule`/`name` in `config/alerting_templates.php` without a bump changes new installs only. With a bump, uncustomized enabled rules move on the next run; customized or turned-off ones never do (a turned-off rule switched back on is upgraded then). There is no migration path for customized rules, by design.
- **Template keys contain a dot** (`logs.error_spike`), so `config("alerting_templates.templates.{$key}")` finds nothing — Laravel splits on every dot. Read the catalogue whole (`config('alerting_templates.templates')[$key]`) through `AlertRule::template()` or `AlertProvisioner::templates()`.
- **Channel linking.** A newly installed template rule is attached to **every enabled channel of the team with `issue_events = true`** — "Team email" is one, and a team that moved issue alerts to Slack gets its default alerts there too. With none, the rule is created unlinked (still evaluated, still on the Alerts page and dashboard). Upgrades never touch channels.
- **Detection costs.** `hasAnyLogs` / `hasAnyTraces` / `hasMetric` run only for kinds that still have a template to install, at most once per kind per run (a host template's kind is `hosts:{metric}` — the metric its rule reads, since `*.utilization` gauges are opt-in in the scraper), so a fully provisioned team costs no ClickHouse read. **Detection never guesses:** it passes `throwOnOverload: true` (the pages' optimistic "yes" under overload would install rules for data never sent); a thrown detection is reported and that kind is skipped (`ProvisionResult::$undetectable`), retried next run. `ProvisionTeamAlerts` also carries `WithoutOverlapping` per team (dropped, not released), and `upgrade()` re-reads the rule under `lockForUpdate` in a transaction so a concurrent user edit is never overwritten.
- **`compare` semantics** (`ReadsAlertValues::comparedWithBaseline()`, logs and traces only; offsets a day or a week; a log `ratio` and every metric refuse it): `count`/`rate` are compared as event counts, `current / max(baseline, 1)` — a group absent last week divides by 1. `error_ratio` and percentiles are `current / baseline`, and with no baseline reading (no events, NaN, 0) the series has **no data** that evaluation: floored at 1 a share could never exceed 1× (a no-op rule), and a new service's 300 ms p95 would read "300× normal". The baseline is one more read through the same query-service method, restricted to the current groups.
- **`min_events`** drops a series with fewer events (rows for logs, spans for traces) in the current window as no data — it stops "1 error vs 0" noise.
- **`project_ids = null` means every project of the team, now and later**; `EvaluateAlertRule::projectIds()` resolves it on every evaluation. Templates always use it.
- **Retirement.** Under `no_data_state = firing`, a series firing only on no data whose last data (or creation) is older than `alerting.retire_after` (24 h) is deleted silently — no resolved event. Otherwise `hosts.silent` would page forever for a decommissioned host.
- **Self-hosters**: `BILIS_ALERTING_PROVISIONING=false` (`alerting.provisioning`) stops both the command and the job; teams keep whatever was already provisioned. `alerting.enabled = false` also stops it.
- **Transparency is part of the deal**: `AlertMessage::TEMPLATE_FOOTER` ends every template rule's notification with a link to tune or turn it off, the webhook's `data.rule.template_key` is set, the Alerts page marks "Set up by Bilis" with the `why` line. `NeedsAttention` (dashboard card) ignores channels on purpose and excludes disabled rules, keeps muted ones; order firing → severity → most recent.
