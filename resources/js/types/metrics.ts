/**
 * The five OTLP metric types, one `otel_metrics_*` table each (SCHEMA.md §2.5).
 */
export type MetricType =
    'gauge' | 'sum' | 'histogram' | 'exponential_histogram' | 'summary';

/** How a gauge's series are combined into one line per group. */
export type MetricAggregation = 'avg' | 'min' | 'max' | 'sum';

/**
 * The explorer's query, as the server validated it and hands it back.
 *
 * The query string spells these `project`, `service`, `metric`,
 * `where[key]=value`, `group_by`, `agg`, `from`, `to`.
 */
export type MetricFilters = {
    project: string | null;
    service: string | null;
    metric: string | null;
    /** Attribute equality filters, at most five. */
    where: Record<string, string>;
    /** One attribute key to split the chart by. */
    groupBy: string | null;
    agg: MetricAggregation;
    /** ISO-8601; the server caps the window at 30 days. */
    from: string;
    to: string;
};

/** One metric the team's projects reported in (or around) the window. */
export type MetricCatalogEntry = {
    name: string;
    type: MetricType;
    /** UCUM-ish as the SDK sent it: `s`, `ms`, `By`, `1`, `{request}`, `%`… */
    unit: string;
    description: string;
    services: string[];
    /** Sums only: a counter (true) or an up-down counter (false). */
    monotonic: boolean;
    /** 0 unspecified, 1 delta, 2 cumulative. Gauges and summaries are 0. */
    temporality: 0 | 1 | 2;
    /** Data points in the catalog's lookback, for ordering and a hint. */
    points: number;
};

export type MetricCatalog = {
    metrics: MetricCatalogEntry[];
    unavailable: boolean;
};

/** An attribute key seen on the selected metric, with its most common values. */
export type MetricAttribute = {
    key: string;
    values: string[];
};

export type MetricAttributes = {
    attributes: MetricAttribute[];
    unavailable: boolean;
};

/**
 * What the chart's y axis means.
 *
 * - `value`: a level — a gauge, or an up-down counter — combined by `agg`.
 * - `rate`: a counter's increase per second; the axis unit is `unit` + `/s`.
 * - `distribution`: percentiles computed from histogram buckets, in `unit`.
 * - `summary`: quantiles the client precomputed, averaged across series, in
 *   `unit`; `approximate` is true.
 */
export type MetricSeriesKind = 'value' | 'rate' | 'distribution' | 'summary';

/**
 * One line on the chart.
 *
 * `stat` is `avg`/`min`/`max`/`sum` for a level, `rate` for a counter, and a
 * percentile — `p50`, `p95`, `p99`, or `p{n}` for a summary's other quantiles —
 * for a distribution. `group` is the `groupBy` attribute's value (`''` when a
 * series lacks the attribute), null when the chart is not grouped.
 */
export type MetricSeries = {
    label: string;
    group: string | null;
    stat: string;
    /** Aligned with `buckets`; null where no point fell in that bucket. */
    points: (number | null)[];
};

export type MetricSeriesResult = {
    metric: string | null;
    type: MetricType | null;
    /** Null when no metric is selected or it has no data in the window. */
    kind: MetricSeriesKind | null;
    unit: string;
    intervalSeconds: number;
    /** Bucket starts, naive UTC `Y-m-d H:i:s` (append `Z` to parse). */
    buckets: string[];
    series: MetricSeries[];
    /** Groups beyond the ten largest, left off the chart. */
    truncatedGroups: number;
    /** Series beyond the 500 read (SCHEMA.md R16), or with histogram bounds that could not be merged. */
    droppedSeries: number;
    approximate: boolean;
    unavailable: boolean;
};
