---
paths:
  - 'app/Services/Metrics/**'
  - app/Http/Controllers/MetricsController.php
---

# Metrics

## All metric SQL lives in MetricQuery; the arithmetic lives in MetricSeriesBuilder
`MetricQuery` is the only reader of the five `otel_metrics_*` tables (the explorer and the MCP tools both call it). Every statement comes from `conditions()`: `ProjectId IN {projectIds:Array(String)}`, the `TimeUnix` window as `DateTime('UTC')` parameters (whole seconds, SCHEMA.md R14), the metric, the service, then each `where` filter as `Attributes[{whereKeyN:String}] = {whereValueN:String}` — keys are bound too, never interpolated. The catalog entry (cached 60 s, looking back at least 24 h) decides the table and the shape, so `series()` never guesses a metric's type from its name.

`MetricSeriesBuilder` is pure — no ClickHouse, no clock — and holds everything SQL cannot do cheaply: per-series deltas of cumulative data (SCHEMA.md R15: a lower value or a new `StartTimeUnix` is a reset and the new value is the whole increase; a series whose first point started inside the read counts from zero, one that started before is only a baseline), merging explicit histograms on the dominant bound set (others reported in `droppedSeries`), merging exponential histograms at the lowest scale present, and percentiles by linear interpolation (`HistogramMath`). Add a shape there with a hand-computed unit test, then prove the SQL in `tests/Feature/Metrics/MetricQueryLiveTest.php`, which runs against a real server.

## A cumulative read starts one bucket early
`series()` moves the read's lower bound back one bucket for cumulative sums and histograms, so the window's first bucket has a previous point to difference against. The builder drops anything before the first displayed bucket. Levels (gauges, up-down counters) and delta data do not need it.

## Caps are part of the contract (SCHEMA.md R16)
At most `SERIES_LIMIT` (500) series are read — the busiest by point count, chosen in a subquery on the same predicate — and `MAX_GROUPS` (10) groups are drawn, heaviest first; the rest are reported as `droppedSeries` / `truncatedGroups` and shown by the page. `MetricFilters` caps the window at the 30-day retention. Raising a cap needs a measured query profile.

## Series keys are strings
The series key is `toString(cityHash64(ServiceName, ResourceAttributes, Attributes))`: a UInt64 above `PHP_INT_MAX` would come back as a float and collide.
