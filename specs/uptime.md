# Uptime monitoring — Specification

Status: agreed 2026-09-25, in progress.

## Purpose and scope

Cheap, scalable, **reliable** uptime monitoring built from what Bilis already has: the pinned
`otelcol-contrib` agent, the OTLP metrics pipeline and the `otel_metrics_*` tables.

- **In:** HTTP(S) checks (method, headers, body assertions, TLS expiry) from Bilis-run probes in
  several regions; in-box checks through the customer's own `bilis-agent`; silent-agent detection;
  an up / degraded / down state per check; incidents; an Uptime page.
- **Out (deliberately):** notifications of any kind (email, webhook, Slack), status pages, TCP/DNS/ICMP
  checks, per-check custom intervals beyond the fixed set, browser/synthetic flows. Alerting stays out
  of scope — an incident is a row and a line on a page, nothing is sent.

## Design principles

1. **Dumb probes, smart centre.** Probes only make requests and report. The verdict is made once, in
   Bilis, by comparing regions.
2. **No new runtime.** A probe is `bilis-agent` in probe mode: the same pinned, SHA-256-verified
   `otelcol-contrib`, the same hardened systemd unit, the same installer.
3. **No new storage format.** Results are OTLP metrics from the `http_check` receiver, stored in the
   existing `otel_metrics_*` tables (30-day TTL) and read through `MetricQuery`.
4. **Pay per unique target, not per check.** Identical requests from different projects are probed once
   and fanned out on ingest.

## Architecture

```
 Bilis (SQLite: uptime_checks)          probe VPS × N regions (bilis-agent --probe)
 ┌──────────────────────────┐  GET config (ETag, 60s timer)  ┌────────────────────────────┐
 │ /api/v1/probe/config     │ ─────────────────────────────▶ │ http_check (1m, 5m)        │
 │ /api/v1/probe/metrics    │ ◀───────── OTLP metrics ────── │ filter → resource(region)  │
 │   └ HandlesOtlpExport +  │                                │ → batch → otlp_http        │
 │     OtlpMetricsMapper    │                                └────────────────────────────┘
 │ uptime:evaluate (1/min)  │ → up/degraded/down per check → uptime_incidents
 └──────────────────────────┘
```

### Checks (SQLite)

`uptime_checks`: `project_id`, `name`, `url`, `method`, `headers` (encrypted), `validations` (JSON, the
receiver's own vocabulary: contains / not_contains / json_path+equals / regex / min_size / max_size),
`interval` (60 or 300 s), `enabled`, `target_key`, current `state`, `state_changed_at`.

`target_key` = hash of the normalised request definition (method, URL, headers, body, validations,
interval). Team-scoped route binding like `{project}` / `{apiKey}`.

### Probe config — `GET /api/v1/probe/config`

- Authenticated by a **probe token** (config `uptime.probes`, one per probe; sha256 stored), never a
  project key. The token names the probe's region and shard.
- Returns the probe's **unique targets** (`crc32(target_key) % shards == shard`), plus the canaries,
  as a ready-to-use collector config fragment. `ETag` = hash of the body; `304` when unchanged.
- Each endpoint is published as `url#bilis=<target_key>`. A fragment is never sent on the wire; it
  survives into the `http.url` attribute so a result maps back to its target exactly.

### Probe ingest — `POST /api/v1/probe/metrics`

A fourth OTLP controller on `Api\Concerns\HandlesOtlpExport` + `OtlpMetricsMapper` (JSON, protobuf,
gzip, 503 + `Retry-After`, partialSuccess — all inherited). The one new step is **fan-out**: each
data point's `target_key` (from the `http.url` fragment) is looked up and the row is written once per
subscribing project, with the fragment stripped from `http.url`. Unknown target → rejected point.

**Invariant exception (approved 2026-09-25):** on this endpoint `ProjectId` comes from Bilis's own
`uptime_checks` table via the target key — not from an API key. The request is authenticated by a
probe token, which can never be a project key. Nothing in the payload names a project.

### Volume control

`httpcheck.status` emits one point per status class (five per run, four always `0`). The probe's
`filter` processor drops the zeros, leaving ≈ 2 points per run (status + duration; `httpcheck.error`
only on failure). A 1-minute check from 3 regions ≈ 8.6k rows/day.

Probe rows carry `service.name = uptime`; the Free-plan metric-points meter excludes that service.
The plan unit for uptime is a soft **uptime checks** count in `config/plans.php` / `PlanLimits`.

### Config sync on the probe

`bilis-agent sync` on a systemd timer (60 s): conditional GET with the stored ETag → on change,
`otelcol-contrib validate` the candidate → atomic swap → restart. Bilis unreachable or config invalid →
keep running the last good config. Restarts reset cumulative sums; R15 reset handling covers it.

Scaling out = raise the region's shard count and add a VPS.

### Evaluator — `uptime:evaluate`

Scheduled every minute, `withoutOverlapping()`. One `MetricQuery` read of the last few minutes per
(check, region), then a **pure** `UptimeStateMachine` (unit-tested without ClickHouse):

- A region's run **fails** on a transport error, a non-2xx/3xx status, or a failed validation.
- **Canary gate:** each probe also checks Bilis itself and two neutral URLs. If a region's canaries
  failed in a minute, its votes for that minute are discarded.
- **Silence:** a region with no data for a check abstains; it does not vote "down".
- **down** = ≥ 2 voting regions fail on 2 consecutive runs. **degraded** = exactly one region fails.
  **up** otherwise. Fewer than 2 voting regions → state held (unknown), never flipped to down.
- A state change opens / closes a row in `uptime_incidents` (`check_id`, `started_at`, `ended_at`,
  `cause`, `regions`).

### In-box checks (customer agent)

`install.sh --check <url>` (repeatable) adds `http_check` to the customer's existing agent, sending
with their own key through `POST /api/v1/metrics` — ProjectId from the key as always. Shown as a
single "local" vote: no quorum, labelled as such.

### Silent agents

The evaluator also flags a host whose host metrics stopped arriving (no points for 3 intervals).
Data already stored; no new ingest.

### UI

**Uptime** under Platform in `AppSidebar.vue`: check list with state, 24 h / 7 d / 30 d uptime bars,
response time by region, incident timeline, TLS days remaining. Checks are created/edited on the
project page. Charts through `ChartCanvas.vue` / `useChartTokens`; new components go in the styleguide.

## Build order

1. ✅ **Spike** — results below.
2. ✅ `install.sh --check` / `--no-checks` (config validated by the 0.159.0 binary, shellcheck clean, mapper
   stores every shape with 0 rejected). ⏳ Real-systemd-box run still owed (needs a local API key).
   Silent-agent detection moves to step 4: it is a job for the evaluator.
3. Probe mode in `install.sh`, `uptime_checks`, probe config + probe ingest endpoints.
4. Evaluator + incidents.
5. Uptime page.

## Spike results (2026-09-25, `otel/opentelemetry-collector-contrib:0.159.0` in Docker, debug exporter)

- [x] **Name:** `http_check` — no deprecation warning in 0.159.0.
- [x] **Fragment:** `#bilis=<key>` survives verbatim into `http.url` **and inside `error.message`**
      (`Get "https://host/#bilis=ccc333": dial tcp: …`). Ingest strips it from both.
- [x] **Concurrency:** all targets of a receiver fire at once. 10 targets × 3 s delay → one 4 s scrape.
- [x] **Throughput** (1 vCPU, local nginx target, 0 errors): 3,000 targets → 2.7 s, 6,000 points;
      10,000 targets → 24 s, 20,000 points, ~600 MiB RSS. Planning figure: **≤ 5,000 targets per probe
      per minute on a 1 vCPU / 2 GB VPS**; shard beyond that.
- [x] **Burst:** concurrency is unbounded, so a probe hits every target in the same instant. Spread load by
      splitting a probe's targets over several receiver instances (`http_check/0..5`) with staggered
      `initial_delay` (0 s, 10 s, … 50 s) — to verify when probe mode is built.
- [x] **Filter:** `metric.name == "httpcheck.status" and value_int == 0` drops the four zero classes —
      4 targets → 12 points instead of 24.
- [x] **What a run emits:**
  - success: `httpcheck.duration` (Gauge, ms) + `httpcheck.status` (Sum, cumulative, not monotonic, value
    1, with `http.status_code`/`http.status_class`/`http.method`) + optional `httpcheck.tls.cert_remaining`
    (Gauge, s; `http.tls.san` is a **Slice** attribute).
  - HTTP error (503): same as success, with `status_class=5xx` — a status point is still emitted.
  - transport error (DNS, timeout, refused): **no status point**; `httpcheck.duration` +
    `httpcheck.error` (value 1, `error.message`).
  - failed validation: status 2xx as normal, plus `httpcheck.validation.failed` (value ≥ 1,
    `validation.type`). `validation.passed` needs enabling and is not needed.
  - Rule for the evaluator: a run **passes** iff a status point with class 2xx/3xx exists at that timestamp
    and no `validation.failed > 0` shares it.
