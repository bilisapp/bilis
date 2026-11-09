# Alerting defaults — Specification

Status: built 2026-11-08 on `feature/alerting`. Builds on `specs/alerting.md`.

## Problem

Alerting works but starts from a blank form: the user must know which signal matters, which
threshold is sane and which window to use. Most teams never get past that, so they get no alerts at
all.

## Goal

**Every team gets useful alerts without configuring anything — existing teams and new ones.** The
user's job changes from *building* rules to *tuning or turning off* the ones Bilis set up.

## Patterns we follow

| Concern | Pattern | What we take |
| --- | --- | --- |
| Alerts on from day one | **Sentry** default alert rule | New-issue / regressed-issue alerts to the whole team by email, created automatically. |
| Curated rules for detected data | **Datadog recommended monitors**, **awesome-prometheus-alerts**, Grafana/Prometheus **mixins** | A versioned catalogue of rule templates, each tied to a kind of data; installed when that data is seen. |
| Declarative, re-runnable setup | **Grafana provisioning** / a Kubernetes-style **reconciler** | A scheduled `alerts:provision` makes each team match the catalogue. It is idempotent, never recreates what a user turned off, and never overwrites what a user edited. |
| Thresholds that fit any traffic | **Datadog/Grafana "change" alerts** (compare with a time shift) | Rules can compare a window with the same window 7 days earlier ("3× normal"), plus a minimum-volume guard. Plain arithmetic, no ML. |
| New services covered automatically | **Prometheus label grouping** | Every template groups by `service.name` or `host.name`, so a new service or host is covered the moment it reports. |

## Design

### 1. Rule engine additions (small, reused by everything below)

- **All projects.** `alert_rules.project_ids = null` means *every project of the team, now and
  later*. Templates use it, so a new project is covered without anyone touching the rule. The job
  resolves it to the team's current project ids.
- **Baseline comparison.** The optional `query.compare = {"offset_seconds": 604800}` (logs and
  traces sources) changes the value to `current / baseline` — floored at 1 for counts only
  (`current / max(baseline, 1)` for logs `count` / `rate` and traces `count`; see the refinement
  below):
  - `current` is the window's reading, and `baseline` is the same reading over the same-length
    window `offset_seconds` earlier;
  - it is one more query through the same query-service method;
  - aggregations that support it: logs `count` / `rate`; traces `count`, `error_ratio`, `p50`,
    `p95`, `p99`;
  - the preview shows the ratio.
  - *Refined while building step 1:* the floor of 1 applies to **counts only** — `count` and
    `rate` are compared as event counts, so an absent baseline divides by 1 and a rate compares
    exactly as its count does. For `error_ratio` and percentiles the value is `current / baseline`,
    and a series with no baseline reading (no events then, or a baseline of 0) has **no data** that
    evaluation: floored at 1 a share could never exceed 1× (the comparison would be a no-op), and a
    new service's 300 ms p95 would read as "300× normal" and fire `traces.latency_regression`.
- **Minimum volume.** The optional `query.min_events = N` drops a series whose event count in the
  current window is below N:
  - the count is rows for logs and spans for traces;
  - a dropped series counts as having no data;
  - this is a single field, not general multi-condition rules, and it stops "1 error vs 0 errors"
    noise.
- **Retired series.** An instance firing **only because of no data** (`no_data_state = firing`)
  for longer than `alerting.retire_after` (24 h) resolves silently and is deleted. Without this, a
  decommissioned host fires forever.

### 2. The template catalogue — `config/alerting_templates.php`

Each entry is the full shape of an `AlertRule`, plus `key`, `version`, `requires` (`logs` \| `traces`
\| `hosts`) and a one-line `why` shown in the UI. v1:

| Key | Requires | Rule | Severity |
| --- | --- | --- | --- |
| `logs.error_spike` | logs | count of ERROR+FATAL logs per service, 10 min, **> 3× the same window last week**, `min_events` 20, every 5 min, keep firing 10 min | warning |
| `traces.error_rate` | traces | `error_ratio` of server spans per service, 10 min, **> 5 %**, `min_events` 50, `for` 5 min, every 5 min | critical |
| `traces.latency_regression` | traces | p95 of server spans per service, 15 min, **> 2× last week**, `min_events` 50, `for` 10 min, every 5 min | warning |
| `hosts.disk_full` | hosts | `system.filesystem.utilization` max per host, 10 min, **> 0.90**, `for` 10 min, every 5 min | critical |
| `hosts.memory_pressure` | hosts | `system.memory.utilization` (`state=used`) avg per host, 10 min, **> 0.95**, `for` 10 min, every 5 min | warning |
| `hosts.silent` | hosts | `system.memory.utilization` last per host, 5 min, condition never true, **`no_data_state = firing`**, `for` 10 min, every 5 min | critical |

The default issue alerts are not templates: they are the default channel with `issue_events = true`
(see 3).

Thresholds are deliberately conservative: defaults that page too often get turned off, and with
them alerting.

### 3. Provisioning — `alerts:provision`

- **Schedule.** Every 15 minutes (`withoutOverlapping`). Also dispatched as a job right after
  `CreateTeam`, so a new team is provisioned at once.
- **Per team, idempotent:**
  1. **Default channel, once.** If `teams.alerting_provisioned_at` is null, create an email channel
     "Team email" (`recipients: members`, `issue_events: true`), link it as the default, and stamp
     the team. A user who later deletes it is never given it back.
  2. **Detection.** Use `LogQuery::hasAnyLogs`, `TraceQuery::hasAnyTraces` and, for a host
     template, `MetricQuery::hasMetric` on the very metric the rule reads (the `*.utilization`
     gauges are opt-in in the host scraper, so "this team has hosts" is not enough) — each over the
     team's projects, at most once per kind or metric per run.
  3. **Templates.** For each template whose `requires` holds:
     - **no rule with that `template_key`:** create it, with `project_ids = null`, linked to the
       team's default channel, and due now;
     - **a rule exists and the user never edited it** (`customized_at` null) **but its
       `template_version` is older:** update it to the new version;
     - **a rule exists and was edited or turned off:** leave it alone.
- **Turning off.** Deleting a template rule turns it off (`enabled = false`) rather than deleting
  the row, so the reconciler sees it and does not recreate it. The UI says "Turn off", not "Delete".
- **Detection failures.** If ClickHouse cannot be read — overload included — that template is
  skipped and retried on the next run. Detection asks the query services to throw rather than
  guess: the optimistic "yes" the pages give under overload would install rules for data the team
  never sent.
- **No extra table.** The rule row (`template_key`, `template_version`, `customized_at`) and
  `teams.alerting_provisioned_at` are all the state there is.

**Existing teams** are provisioned by the first scheduled run after deploy, exactly like new ones.
That is intended (decision 2026-11-08: "as automatic as possible for existing and new teams").

### 4. Transparency (the price of being automatic)

- **Every notification from a template rule** ends with: "Bilis set this alert up automatically.
  Tune it or turn it off: {link}". The webhook payload carries `rule.template_key`.
- **Alerts page.**
  - Template rules show a "Set up by Bilis" mark and their `why` line.
  - A dismissible banner: "Bilis set up N alerts for the data you send."
  - Editing a template rule sets `customized_at`, and the editor says that it will stop receiving
    Bilis' updates.
  - *Added 2026-11-09:* a **Templates** tab (`alerts.templates.index`) lists the whole catalogue
    with each template's status for the team (`on` / `off` / `available`) — app DB only, no
    detection read. **Turn on** (`alerts.templates.activate` → `AlertProvisioner::activate()`)
    installs a missing template exactly as provisioning would (all projects, issue-alert
    channels) but **without detection**, or re-enables a turned-off rule (due next tick,
    definition untouched). It never creates a second rule.

### 5. "Needs attention" on the dashboard

A deferred `needsAttention` prop on `DashboardController`: the team's firing instances, plus pending
ones marked as such, at most 8, most severe first. Each shows rule name, labels, value vs threshold
in words ("4.2× normal", "93 %") and since when, linking to the explorer. It is rendered by a
`NeedsAttentionCard.vue` (styleguide too) and hidden when empty. It shows even for a team with no
working channel, so the dashboard alone surfaces what matters.

## Out of scope

ML anomaly detection, seasonality models, forecasting, per-template tuning wizards, and auto-tuning
thresholds from feedback. Revisit after real data shows which defaults are noisy.

## Build order

- [x] 1. Engine:
   - migrations `alert_rules.template_key/template_version/customized_at` and
     `teams.alerting_provisioned_at`;
   - `project_ids = null`, `compare`, `min_events`, retired no-data series.
- [x] 2. Catalogue + `alerts:provision` + the `CreateTeam` hook.
- [x] 3. UI:
   - transparency on the Alerts page and in notifications;
   - the "Needs attention" card on the dashboard;
   - the styleguide.
- [x] 4. Docs: `CLAUDE.md`, `.ai/rules/alerting.md`, `resources/docs/reference/alerting.md`
   ("Default alerts").

## Deviations found in the built code

- **Channel linking.** A new template rule is linked to **every enabled channel of the team with
  `issue_events = true`**, not to "the default channel" — "Team email" is one, and a team that moved
  issue alerts to Slack gets its defaults there too. With none, the rule is created unlinked (still
  evaluated, still on the Alerts page and dashboard). Upgrades never touch channels.
- **Upgrades skip turned-off rules.** Only an `enabled`, uncustomized rule with an older
  `template_version` is upgraded; one switched back on is upgraded on the next run.
- **What customises.** `customized_at` is stamped only when a rule-defining field really changes
  (`AlertsController::DEFINITION_FIELDS`: name, source, projects, query, condition, threshold,
  timings, no-data state, severity, compared canonically). Channels, mute and enable/disable do not.
- **Turn-off wording.** The row action and confirm button read "Turn off"; the dialog says "Turned
  off, it stops evaluating and Bilis will not set it up again. You can switch it back on from this
  list at any time." The toast reads "Alert rule turned off."
- **Dotted keys.** Template keys contain a dot (`logs.error_spike`), so a dotted `config()` path
  cannot reach an entry. The catalogue is read whole, through `AlertRule::template()` /
  `AlertProvisioner::templates()`.
- **Compare offsets.** `compare.offset_seconds` is one day or one week (`ReadsAlertValues::COMPARE_OFFSETS`);
  a log `ratio` and metric rules refuse `compare`.
- **"Needs attention" ordering.** Firing before pending, then severity (critical → warning → info),
  then most recent first. Disabled rules are excluded; muted ones stay in.
- **Banner dismissal.** "Bilis set up N alerts" is dismissed in `localStorage`, keyed per user and
  team, storing the count dismissed — a new template rule brings it back. With storage unavailable
  it shows, and dismissing hides it for the visit.
- **Detection overload.** `hasAnyLogs` / `hasAnyTraces` answer *yes* under ClickHouse overload for
  the pages, so provisioning calls them with `throwOnOverload: true`; `hasMetric` always throws.
  Any failed detection counts as "skipped, retried next run" (`ProvisionResult::$undetectable`).
- **Host templates detect their own metric.** `requires: hosts` is checked as "the team reports the
  metric this rule reads", not "the team has hosts" — a host without the utilization gauges would
  otherwise get a `hosts.silent` rule whose no-data state fires for a series that never existed.
