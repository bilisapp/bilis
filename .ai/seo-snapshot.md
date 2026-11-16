# SEO Snapshot

**Snapshot date:** 2026-11-16
**Source:** OpenSEO (project `bilis.app`, id `7e90b0e8-8d5c-49d4-8717-b07e1df4c2d9`, US/en): keyword metrics, weekly desktop rank tracker (34 keywords, last checked 2026-11-14), site audit (63 pages, 2026-11-16); Google Search Console (`sc-domain:bilis.app`, 2026-08-13 → 2026-11-13)
**Refreshed by:** the owner locally with `/seo-refresh` (OpenSEO and Search Console are not reachable from cloud routines)

This file is the cloud growth agent's only view of search data. Treat it as read-only: if it looks stale or wrong, say so in the run note instead of editing it. When it's older than 21 days, flag "SEO snapshot is stale, please run /seo-refresh" in your report.

All volumes are US monthly searches (Google Ads-derived, so close variants share a number). KD = keyword difficulty 0–100. Volumes are measurements from 2026-11-16 unless marked otherwise: never invent or extrapolate one.

---

## 1. Search Console (last 3 months, 2026-08-13 → 2026-11-13)

**Totals are tiny but real:** 29 clicks, ~1,900 impressions. Almost all non-brand impressions come from three free tools.

| Page | Clicks | Impr. | CTR | Avg pos. |
|---|---|---|---|---|
| /tools/timestamp-converter | 5 | 716 | 0.7% | 17.1 |
| / | 7 | 340 | 2.1% | 9.8 |
| /tools/traceparent | 7 | 155 | 4.5% | 14.6 |
| /tools/log-cost-calculator | 0 | 85 | 0% | 14.8 |
| /docs/ingestion/claude-code | 0 | 64 | 0% | 7.0 |
| /features | 2 | 45 | 4.4% | 6.3 |
| https://www.bilis.app/ (duplicate host) | 4 | 41 | 9.8% | 4.5 |
| /pricing | 0 | 27 | 0% | 13.2 |
| /docs/ingestion/traces | 0 | 20 | 0% | 6.0 |
| /tools/log-levels | 0 | 13 | 0% | 22.2 |
| /docs | 0 | 12 | 0% | 16.4 |
| /tools | 0 | 8 | 0% | 5.0 |
| /features/mcp | 0 | 6 | 0% | 7.7 |
| /compare/datadog | 0 | 3 | 0% | 7.0 |

### Striking distance (position 5–25 with impressions, mapped to measured volume)

| Query | Page | Impr. | Pos. | Vol/mo | KD |
|---|---|---|---|---|---|
| convert time to unix timestamp | /tools/timestamp-converter | 11 | 12.1 | 18,100 | 27 |
| convert time to unix time | /tools/timestamp-converter | 9 | 12.1 | (variant of above) | |
| timestamp to unix time | /tools/timestamp-converter | 13 | 20.0 | 14,800 | 20 |
| time to unix timestamp | /tools/timestamp-converter | 14 | 17.3 | 9,900 | 20 |
| posix timestamp | /tools/timestamp-converter | 6 | 17.2 | 9,900 | 26 |
| unix ts converter | /tools/timestamp-converter | 6 | 18.2 | 49,500 | 17 |
| unix to timestamp | /tools/timestamp-converter | 6 | 17.3 | not measured | |
| unix time to timestamp | /tools/timestamp-converter | 5 | 12.8 | not measured | |
| date to unix timestamp | /tools/timestamp-converter | 2 | 11.0 | 1,900 | 26 |
| milliseconds to date converter | /tools/timestamp-converter | 5 | 11.4 | 50 | 11 |
| unix timestamp milliseconds | /tools/timestamp-converter | 6 | 20.8 | 260 | 32 |
| timestamp to ist / to pst | /tools/timestamp-converter | 6 / 2 | 15.8 / 15.5 | 10 / 110 | – / 33 |
| unix timestamp converter | /tools/timestamp-converter | 4 | 5.3 | 40,500 | 21 |
| trace decoder | /tools/traceparent | 17 | 8.1 | not measured | |
| traceparent generator | /tools/traceparent | 28 | 3.4 | 10 | – |
| price per gb calculator | /tools/log-cost-calculator | 6 | 19.5 | 40 | 13 |
| threat detection platform cost estimate for 5tb logs | /tools/log-cost-calculator | 12 | 5.1 | not measured | |
| otel_log_raw_api_bodies / otelheadershelper | /docs/ingestion/claude-code | 1 / 1 | 7 / 8 | not measured | |
| bilis | / | 130 | 12.2 | 1,900 (mostly not us: the word means "bile" in several languages) | 11 |

Note: the GSC per-query position for "unix timestamp converter" (5.3) is from 4 impressions; the rank tracker (2026-11-14, desktop, US, depth 100) did **not** find bilis.app for it. Trust the tracker for head terms.

## 2. What the data says (strategy)

1. **The timestamp converter is the traffic engine.** It holds 38% of all impressions and sits on page 2 for a cluster worth >100k searches/mo at KD 17–27 ("unix ts converter" 49.5k, "epoch converter" 40.5k, "unix timestamp converter" 40.5k, "convert time to unix timestamp" 18.1k, "timestamp to unix time" 14.8k, "time to unix timestamp" 9.9k, "posix timestamp" 9.9k). Its CTR is 0.7%. Searchers want **date → timestamp** as much as timestamp → date; the title and H1 lead with "Unix timestamp converter … down to the nanosecond", which fits a niche, not the head. Moving page 2 → page 1 here is the single biggest traffic lever on the site, even though these visitors are developers, not buyers. It also builds the domain's authority for the money pages.
2. **Low-KD commercial terms are open and nobody on the site targets most of them.** "datadog alternative" 480 (KD 0, CPC $107), "datadog alternatives" 480 (KD 0), "log management tool" 390 (KD 1), "log aggregation" 390 (KD 3), "sentry alternative" 210 (KD 0), "new relic alternative" 90 (KD 0), "datadog vs new relic" 140 (KD 4), "signoz vs grafana" 70 (KD 0). Only "datadog alternative" has a page (/compare/datadog, not ranking in the top 100 yet).
3. **Claude Code observability is the on-brand niche and it is cheap.** "claude code monitoring" 390 (KD 6), "claude code telemetry" 170 (KD 12), "claude code opentelemetry" 90 (KD 35). /docs/ingestion/claude-code already gets 64 impressions at position 7 with 0 clicks: its title is just "Claude Code — Bilis docs". The MCP blog post (`resources/blog/2026-10-10-production-logs-for-your-coding-agent.md`) is still `draft: true`.
4. **Informational OTel terms with weak KD:** "otlp" 1,600 (KD 16), "log level"/"log levels" 1,900 each (KD 10–11; /tools/log-levels sits at ~56), "syslog severity levels" 260 (KD 9), "traceparent" 140 (KD 3), "traceparent header" 90 (KD 3; tracked at 38), "w3c trace context" 170 (KD 26), "laravel logging" 140 (KD 0), "clickhouse logs" 70 (KD 9), "clickhouse observability" 90 (KD 4). Dead ends: "opentelemetry collector" 1,000 is KD 76 (navigational to the OTel project); "self hosted *" phrasings measure ≤ 10/mo.
5. **Positioning constraint:** never "open source". Target "open source datadog alternative" (30/mo) only with honest "source-available" copy, or not at all.

## 3. Keyword targets (US, measured 2026-11-16)

Status: `match` = title/H1 already uses the phrasing; `page` = a page exists but doesn't; `none` = no page. Position = rank tracker 2026-11-14 (desktop, top 100), `–` = not found in top 100, blank = not tracked.

### Tier 1: page exists, low/moderate KD (do these first)

| Keyword | Vol/mo | KD | Target page | Status | Pos. |
|---|---|---|---|---|---|
| unix ts converter | 49,500 | 17 | /tools/timestamp-converter | page | |
| epoch converter | 40,500 | 17 | /tools/timestamp-converter | page | |
| unix timestamp converter | 40,500 | 21 | /tools/timestamp-converter | match | – |
| convert time to unix timestamp | 18,100 | 27 | /tools/timestamp-converter | page | |
| timestamp to unix time | 14,800 | 20 | /tools/timestamp-converter | page | |
| time to unix timestamp | 9,900 | 20 | /tools/timestamp-converter | page | |
| posix timestamp | 9,900 | 26 | /tools/timestamp-converter | page | |
| timestamp converter | 5,400 | 28 | /tools/timestamp-converter | page | |
| unix time converter | 4,400 | 38 | /tools/timestamp-converter | page | |
| date to unix timestamp | 1,900 | 26 | /tools/timestamp-converter | page | |
| milliseconds to date | 720 | 6 | /tools/timestamp-converter | page | |
| unix timestamp milliseconds | 260 | 32 | /tools/timestamp-converter | page | |
| unix timestamp nanoseconds | 10 | 27 | /tools/timestamp-converter | match | 20 |
| nanosecond timestamp converter | 10 | – | /tools/timestamp-converter | match | 25 |
| log level | 1,900 | 10 | /tools/log-levels | page | – |
| log levels | 1,900 | 11 | /tools/log-levels | match | – |
| syslog severity levels | 260 | 9 | /tools/log-levels | page | – |
| log severity levels | 40 | 0 | /tools/log-levels | page | – |
| datadog pricing calculator | 480 | 3 | /tools/log-cost-calculator | page | – |
| log volume calculator | 260 | 0 | /tools/log-cost-calculator | match | – |
| logging pricing | 260 | 23 | /tools/log-cost-calculator | page | |
| log pricing | 210 | 18 | /tools/log-cost-calculator | page | |
| datadog cost calculator | 50 | 8 | /tools/log-cost-calculator | page | – |
| traceparent | 140 | 3 | /tools/traceparent | match | |
| traceparent header | 90 | 3 | /tools/traceparent | page | 38 |
| traceparent decoder | – | – | /tools/traceparent | match | 78 |
| w3c trace context | 170 | 26 | /tools/traceparent | page | – |
| w3c traceparent | 70 | 20 | /tools/traceparent | page | |
| datadog alternative | 480 | 0 | /compare/datadog | match | – |
| datadog alternatives | 480 | 0 | /compare/datadog | page | |
| claude code monitoring | 390 | 6 | /docs/ingestion/claude-code | page | |
| claude code telemetry | 170 | 12 | /docs/ingestion/claude-code | page | |
| claude code opentelemetry | 90 | 35 | /docs/ingestion/claude-code | page | |
| mcp observability | 50 | 0 | /features/mcp | page | – |
| laravel logging | 140 | 0 | /docs/ingestion/shippers | page | – |

### Tier 2: no page yet (new indexable page, cite the row)

| Keyword | Vol/mo | KD | CPC | Suggested page | Notes |
|---|---|---|---|---|---|
| otlp | 1,600 | 16 | $40 | docs or blog explainer ("What is OTLP") | informational; link to /docs/ingestion/endpoints |
| log management tool | 390 | 1 | $81 | features-style landing or blog | commercial |
| log aggregation | 390 | 3 | $91 | blog explainer | informational |
| distributed tracing | 480 | 29 | $15 | blog explainer | harder |
| sentry alternative | 210 | 0 | $71 | /compare/sentry | Bilis has Issues + accepts Sentry-SDK envelopes; see rule below on vendor names |
| datadog vs new relic | 140 | 4 | $42 | blog | must stay neutral and checkable |
| new relic alternative | 90 | 0 | $59 | /compare/new-relic | |
| clickhouse observability | 90 | 4 | $5 | blog | |
| clickhouse logs | 70 | 9 | $17 | blog ("storing logs in ClickHouse") | we have the schema story already |
| signoz vs grafana | 70 | 0 | $84 | — | we are neither; skip unless honest framing exists |
| otel collector config | 50 | 15 | – | docs | |
| open source log management | 50 | 14 | $159 | — | "open source" phrasing: be careful |
| signoz alternative | 30 | 6 | $30 | /compare/signoz | |
| signoz vs datadog | 30 | 4 | $24 | — | |
| opentelemetry php | 30 | 0 | $6 | docs/blog | |
| open source datadog alternative | 30 | 0 | $25 | /compare/datadog | only with "source-available" copy |

### Not yet measured (don't cite a volume for these)

trace decoder, unix to timestamp, unix time to timestamp, timestamp to time, epoch to date, current unix timestamp, opentelemetry log levels, otel severity number, cursor mcp logs, mcp server logs, claude code hooks logging.

### Measured dead ends (≤ 10/mo or navigational to others)

self hosted datadog alternative 10, self hosted log management 10, self hosted sentry alternative 10, observability mcp server 10, openobserve alternative 10, grafana loki alternative 10, laravel opentelemetry 10, opentelemetry laravel 10, otlp http 20, opentelemetry collector 1,000 at KD 76, opentelemetry traces 320 at KD 42, opentelemetry go 90 at KD 45.

## 4. Competitors

| Domain | Notes |
|---|---|
| signoz.io | OTel-native, ClickHouse, open source; dominates "open source datadog alternative" SERPs |
| dash0.com | OTel-native SaaS; ranks with a /datadog-alternative landing page |
| uptrace.dev | OTel + ClickHouse; comparison content ranks |
| openobserve.ai | Self-hosted, AGPL; ranks with a docs comparison page |
| hyperdx.io | ClickHouse-based (ClickStack) |
| datadoghq.com | Price anchor: $0.10/GB ingest + $1.70/M indexed events (15 d, annual), checked 2026-10-09. Has an MCP server |
| epochconverter.com, unixtimestamp.com, currentmillis.com | Own the timestamp SERPs (not measured this refresh; check the SERP before rewriting the converter) |

## 5. Site audit (2026-11-16, 63 pages, no JS rendering)

Healthy: no broken links, no missing titles/descriptions on public pages, canonicals present, sitemap (38 URLs) and robots.txt fine, auth pages `noindex`.

| Issue | Pages | Fix |
|---|---|---|
| Title > 60 chars | /tools/crawler-view (66), /blog/bilis-traces-itself (64), /blog/no-protobuf-library (62) | shorten, keyword first |
| Meta description > 160 | /tools (163), /tools/crawler-view (192) | trim |
| Missing H1 | /styleguide (noindex, low priority) | — |
| `www.bilis.app` and `http://` redirect with **302/307**, not 301 | host-level | owner: fix in Traefik/Coolify (not in the repo); www.bilis.app/ has 41 impressions as a separate URL |
| /llms.txt returns 404 | — | `[needs decision]`: new public route |

## 6. Work queue (prioritised; skip items covered by an open `loop/` PR)

1. **Timestamp converter: win the date → timestamp intent.** `resources/views/marketing/tools/timestamp.blade.php` (+ `ToolCatalog` summary). Retitle toward "Unix timestamp converter (epoch ↔ date)…" keeping ns/ms; make the date → timestamp direction visible above the fold; add a short explanation section and an FAQ answering "how do I convert a date to a Unix timestamp", "seconds vs milliseconds vs nanoseconds", "what is POSIX time", "what is the current epoch time" with code snippets (JS, Python, PHP, Go, SQL/ClickHouse); `FAQPage` JSON-LD only if the FAQ is visible on the page. Evidence: §1 striking distance (pos 12–20 for 9.9k–49.5k terms, CTR 0.7%).
2. **Claude Code docs page: title and intro for "claude code monitoring/telemetry".** `resources/docs/ingestion/claude-code.md` front matter title/description and the opening paragraph. Evidence: 64 impressions at pos 7, 0 clicks; "claude code monitoring" 390/KD 6, "claude code telemetry" 170/KD 12.
3. **Log cost calculator: target "datadog pricing calculator".** `resources/views/marketing/tools/log-cost.blade.php` title/H1/description and intro, honest that it estimates Datadog-style log pricing from public list prices (dated). Evidence: 480/mo KD 3, page at pos ~15 for related queries, not in top 100 for the head term.
4. **Log levels: depth for "log level(s)" and "syslog severity levels".** `resources/views/marketing/tools/log-levels.blade.php`: an explainer section (what each level means, when to use it, RFC 5424 numeric codes, OTel SeverityNumber ranges) and FAQ. Evidence: 1,900 + 1,900 + 260/mo at KD 9–11; page at ~56.
5. **Traceparent: "traceparent header" / "w3c trace context".** `resources/views/marketing/tools/traceparent.blade.php`: field-by-field explanation of the header, `tracestate`, examples, FAQ. Evidence: 155 impressions at 14.6, tracked "traceparent header" at 38 (from 47).
6. **Audit fixes** (§5): crawler-view title/description, /tools description, the two long blog titles. Small; bundle into one PR.
7. **/compare/datadog: add "datadog alternatives" phrasing and internal links** from /tools/log-cost-calculator, /pricing and /features. Evidence: 480 + 480/mo at KD 0, page not in top 100.
8. **New page: "What is OTLP"** (docs or blog), linking to the endpoints docs. Evidence: "otlp" 1,600/mo KD 16.
9. **New compare page: /compare/sentry** (Issues + the envelope endpoint), only if it can be done honestly: name the trademark owner, state what Bilis lacks (performance product, session replay, etc. — verify), keep vendor names out of code identifiers. Evidence: "sentry alternative" 210/mo KD 0.
10. **Internal linking:** every /tools page links to the related docs page and to one money page (/compare/datadog or /features/mcp); docs ingestion pages link to the matching tool (timestamps → converter, severity → log levels, traces → traceparent).

## 7. Owner-only actions (remind in reports while open)

- Make `www.bilis.app` and `http://` redirect with **301** (Traefik/Coolify).
- Decide whether to publish the MCP post (`resources/blog/2026-10-10-production-logs-for-your-coding-agent.md`, `draft: true`).
- Directory listings for referring domains (awesome-selfhosted, OpenTelemetry registry, AlternativeTo) — from the 2026-10-10 plan.
- Run `/seo-refresh` every 2–3 weeks.

## Changelog

| Date | What changed |
|---|---|
| 2026-11-16 | First snapshot: GSC 3 months, 82 keywords measured, rank tracker 2026-11-14, fresh site audit, work queue v1 |
