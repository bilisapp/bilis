# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Primary: the solo developer or two-to-three-person team that self-hosts.** They run side projects or a small SaaS on their own VPS or dedicated box, deploy through something like Coolify, and are both the operator and the only reader of the logs. There is no platform team between them and the box.

The defining situation is debugging: something is wrong in production, they have a timestamp or a service name or a fragment of an error string, and they need the surrounding log lines fast. The secondary situation is passive — a live tail left open in a tab while a deploy goes out.

They chose to self-host deliberately. Cost and data ownership are motives; so is not wanting to operate a Grafana/Loki/ELK stack to read text.

## Product Purpose

Bilis stores and searches application logs on infrastructure the user owns. It accepts logs over OTLP/HTTP (plus a simple JSON fallback), stores them in ClickHouse using an OTel-compatible schema, and gives them a log viewer with time range, project/service/severity filters, full-text search, and live tail.

Success is that the user finds the line they need in seconds, and that running Bilis never becomes its own operational project.

## Positioning

Three claims, all of which must stay literally true:

- **Self-hosted, no per-GB pricing.** The user owns the box and the data. Cost is disk, not a metered bill. This is the explicit anti-Datadog / anti-Betterstack position.
- **Simple to run.** One Laravel app plus one ClickHouse. Deployable in minutes. Radically less machinery than Loki, ELK, or a Grafana stack — that operational simplicity *is* the product, not a side effect.
- **OTel-native, no lock-in.** Standard OTLP/HTTP ingest, standard OTel columns. Any existing OTel exporter can be pointed at it, and the data can be taken elsewhere.

A neighboring hosted product cannot truthfully claim the first; a neighboring self-hosted stack cannot truthfully claim the second.

## Operating Context

- Ingest is machine-to-machine: an OTel exporter or an app's HTTP client POSTs to `/api/v1/logs` or `/api/v1/ingest`, authenticated by a `bilis_`-prefixed API key that maps to exactly one project.
- The UI is team-scoped. Routes are prefixed by team slug; a team owns projects; projects are the unit of log separation and of API keys.
- Deploy target is Traefik via Coolify on a single OVH dedicated box, running Octane/FrankenPHP.
- The viewer is read for long stretches, often in dark mode, often on a laptop next to a terminal. Live tail means the page updates while unattended.

## Capabilities and Constraints

**In scope (v1, exactly this):** OTLP/HTTP JSON ingest + simple JSON fallback; OTel-compatible ClickHouse logs table; log viewer with time range, project/service/severity filters, full-text search, and live tail. Teams, projects, API keys, and auth support that.

**Explicitly not in v1:** traces, metrics, alerting, dashboards, saved searches, eBPF, S3 tiering, replication, billing. Scope creep gets pushed back on.

**Technical constraints that shape the interface:**

- Ingest never returns 400. Malformed records are skipped best-effort with counts. ClickHouse failure returns 503 + `Retry-After`. The client is never blamed.
- Inserts are async (`async_insert=1`, `wait_for_async_insert=0`) — a successful ingest means *queued*, not durable. Any UI language about ingest must not overpromise.
- ClickHouse is reached over its HTTP interface via a hand-rolled client; no ClickHouse composer package.
- OTLP protobuf content-type returns 415. JSON encoding only in v1.
- App DB is SQLite; logs live only in ClickHouse.

**Terminology:** *project* (log namespace + API key scope), *service* (`service.name` within a project), *severity* (trace/debug/info/warn/error/fatal), *live tail*, *ingest*.

**Undecided:** the shape and timing of the planned hosted tier; whether the current `/` Welcome page becomes a real marketing surface.

## Brand Commitments

- **Name:** Bilis. Wordmark is "Bilis" with the three-stripes mark (`AppLogo.vue` / `AppLogoIcon.vue`).
- **Palette:** derived from a mid-century stripes artwork, defined in `resources/css/app.css` — brand utilities `cream, greige, espresso, navy, gold, crimson, teal, aqua, blush`; semantic shadcn tokens (light = navy on cream, dark = gold on espresso); per-mode severity utilities `text-severity-{trace,debug,info,warn,error,fatal}`. Light mode holds a strict three-level surface hierarchy.
- **Typography:** Instrument Sans is current. IBM Plex Sans/Mono are loaded alongside it as candidates under evaluation on `/styleguide`; the decision is open and the losers get removed once made.
- The `/styleguide` page is the living reference. Every reusable component belongs in it.

These are the incumbent state recorded from the codebase, not constraints the user declared immovable.

## Evidence on Hand

**Real:** the working product itself — ingest endpoints, ClickHouse schema, log viewer, teams/projects/API keys, and the `/styleguide` showcase. A live demo or a real screenshot of the viewer with real log volume is the strongest asset available.

**Does not exist, and must never be fabricated:** customers, users, testimonials, customer logos, case studies, press mentions, adoption or volume numbers, benchmarks, uptime figures, GitHub stars. Bilis is pre-launch with no users yet. Any marketing surface has to persuade on the product and the position alone.

**Pricing:** the hosted service at bilis.app has one plan — **Free**, with published limits (`config/plans.php`, read through `App\Services\Plans\PlanLimits`) and no card. Every allowance is **soft**: it is measured and warned about, never enforced, and no telemetry is dropped or blocked for being over it. Anything larger is a **Team** plan arranged by contact (`/contact?topic=upgrade`); there is no checkout and no self-serve billing, so nothing may be presented as currently purchasable. Self-hosting stays first-class and free, and has no plan at all.

## Product Principles

1. **The log line is the product.** Every surface exists to get the user to the right lines faster. Chrome that competes with the log stream for attention is a defect.
2. **Operational simplicity is a feature, and it must be visible.** If Bilis looks or feels as heavy as the stacks it replaces, the positioning is dead — including in how setup, ingest, and errors are presented.
3. **Never blame the client.** The ingest contract is forgiving by design; the interface should carry the same posture toward the user, especially in empty, error, and no-data-yet states.
4. **Stay ruthlessly scoped.** Logs only. Resist surfaces that imply traces, metrics, alerting, or dashboards exist.
5. **Tell the truth about state.** Queued is not durable; pre-launch is not adopted; planned is not available. Precision here is part of the trust the self-host audience is buying.

## Accessibility & Inclusion

Target WCAG 2.1 AA — contrast, full keyboard operability, and honored `prefers-reduced-motion` — as a working baseline, with no formal audit or compliance process to satisfy.

The practical bar beyond that comes from the usage scene: long dark-mode reading sessions, dense monospace log text, and severity that must remain distinguishable without relying on color alone.
