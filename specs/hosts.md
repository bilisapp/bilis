# Hosts view — Specification

Status: agreed 2026-10-05, in progress.

## Problem

Someone who runs the one-line installer wants to know how their server is doing. The metrics page
answered a different question — "chart this metric I already know the name of" — and failed the
first one in four ways:

1. Nothing is charted until a metric is picked.
2. The starting list ranks metrics by point count, so on a Docker host thirty containers × twenty
   `container.*` series bury CPU, memory and disk.
3. It speaks exporter names (`system.filesystem.usage`, Sum, By) rather than questions ("is the
   disk full?").
4. Group-by and filters only read data-point `Attributes`, but `host.name` and `container.name`
   are **resource** attributes — so the installer's own hint, "group by host.name", could not work.

## Scope

- **In:** a *Hosts* tab on the metrics page — one row per `host.name` with CPU %, memory %, the
  fullest filesystem, load and container count, then a fixed set of charts for the selected host;
  group-by and filters that fall back to resource attributes; the installer turning on the host
  scraper's `*.utilization` metrics.
- **Out:** user-defined dashboards, saved views, alerting, rollups, per-host thresholds. The curated
  charts are fixed, read-only, and built from `MetricQuery::series()` — no new query shape.

## Design

### Which hosts

A host is any `host.name` that reported a host-metrics receiver metric (`system.memory.usage` or
`system.cpu.load_average.1m`) in the window — regardless of `service.name`, so a hand-written
Collector config is found as well as `bilis-agent`'s.

### The host row — "now"

Taken from the **last 15 minutes of the window** (`MetricQuery::hosts()`, one UNION ALL round trip):

| Column | Source | Reading |
|---|---|---|
| CPU | `system.cpu.time` (cumulative, per `cpu` × `state`) | per-series delta (R15; a negative delta is a reset, clamped to 0), then `1 − idle / total` |
| Memory | `system.memory.usage` (per `state`) | latest per state; `used / Σ states` |
| Disk | `system.filesystem.usage` (per `device` × `mountpoint` × `state`) | latest per state; `used / (used + free)` per mount (df's Use%), the fullest mount |
| Load | `system.cpu.load_average.1m` | latest |
| Containers | `container.cpu.usage.total` | distinct `container.name` |
| Last seen | any of the above | max time in the whole window |

These derive from the scraper's default metrics, so an install that predates the utilization flags
still gets every column. A host silent for the last 15 minutes keeps its row, with dashes and an
old "last seen" — that is the signal, not an error.

### The host's charts

Fixed list in `App\Services\Metrics\HostCharts`. Each chart names candidates in preference order;
the first present in the catalog is charted. Each is filtered by `host.name` and may rescale the
result for display (ratio → %, ns/s → cores).

| Chart | Preferred | Fallback |
|---|---|---|
| CPU | `system.cpu.utilization` by `state`, avg, as % | `system.cpu.time` by `state`, rate, as cores |
| Load | `system.cpu.load_average.1m` | — |
| Memory | `system.memory.utilization` by `state`, avg, as % | `system.memory.usage` by `state` |
| Disk space | `system.filesystem.utilization` by `mountpoint`, max, as % | `system.filesystem.usage` where `state=used`, by `mountpoint` |
| Disk I/O | `system.disk.io` by `direction` | — |
| Network | `system.network.io` by `direction` | — |
| Container CPU | `container.cpu.usage.total` by `container.name`, as cores | — (only when the host has containers) |
| Container memory | `container.memory.usage.total` by `container.name` | — (only when the host has containers) |

Each chart links to the explorer with the same metric, filter and grouping, so a curated chart is
always one click from the full controls.

### Navigation

- `GET /{team}/metrics/hosts` (`metrics.hosts`): query `project`, `host`, `from`, `to`. `host`
  defaults to the first host listed.
- Tabs *Hosts* / *Explorer* share the window and project, like the trace tabs.
- A bare `/{team}/metrics` (no query string at all — the sidebar link) redirects to Hosts when the
  team has host data. Any explorer link carries a window, so the explorer stays reachable.

### Resource-attribute fallback

`where[k]=v` and `group_by=k` read `Attributes[k]` when the point has it, else
`ResourceAttributes[k]` — the same precedence OTel gives a data point over its resource. The
attribute picker offers resource keys too (minus `service.name`, which has its own field, and
`telemetry.*`).

### Installer

The host-metrics scraper enables `system.cpu.utilization`, `system.memory.utilization` and
`system.filesystem.utilization`. Cost: about one extra row per CPU × state per minute — tens of
thousands of points a day on a small box, well inside the Free plan's million.
