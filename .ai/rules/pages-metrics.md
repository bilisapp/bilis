---
paths:
  - 'resources/js/pages/metrics/**'
---

# Pages Metrics

## Metric Live is short polling, not SSE/long-poll
`useMetricsLive` re-reads the whole relative window with an async partial reload (`only` the chart props) every `liveRefreshMs()` — half a bucket, clamped 30 s–5 min — one at a time, skipped while the tab is hidden, cancelled by any sync navigation. Ingest has no push step, so a held SSE/long-poll connection would just poll ClickHouse while pinning an Octane worker. Never append ticks: cumulative deltas, the 500-series cap and the 10-group cut shift as the window slides. A rate's still-open newest bucket is hidden (`withoutOpenBucket`), because a delta counter's rate divides by the full bucket width and would dip at the right edge.
