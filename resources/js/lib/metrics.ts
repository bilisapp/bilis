import { formatBytes, RANGE_PRESETS } from '@/lib/logs';
import { formatDuration } from '@/lib/traces';
import type {
    LogRangePreset,
    MetricCatalogEntry,
    MetricFilters,
    MetricSeries,
    MetricSeriesKind,
    MetricSeriesResult,
    MetricType,
} from '@/types';

/** The most attribute filters the server accepts; a sixth is dropped there. */
export const MAX_METRIC_FILTERS = 5;

/** The combination a level falls back to, and the one the URL leaves out. */
export const DEFAULT_METRIC_AGGREGATION = 'avg';

export const METRIC_AGGREGATIONS = [
    { value: 'avg', label: 'Average' },
    { value: 'min', label: 'Minimum' },
    { value: 'max', label: 'Maximum' },
    { value: 'sum', label: 'Sum' },
] as const;

/**
 * The percentiles a grouped distribution can be read at, one at a time.
 *
 * Ungrouped, every stat the server sent is drawn; grouped, three lines per
 * group would put thirty lines on one chart, so the reader picks the stat and
 * gets one line per group.
 */
export const METRIC_PERCENTILES = ['p50', 'p95', 'p99'] as const;

export type MetricPercentile = (typeof METRIC_PERCENTILES)[number];

export const DEFAULT_METRIC_PERCENTILE: MetricPercentile = 'p95';

/** How a metric type reads in a picker, short enough to sit beside a name. */
export const METRIC_TYPE_LABEL: Record<MetricType, string> = {
    gauge: 'Gauge',
    sum: 'Sum',
    histogram: 'Histogram',
    exponential_histogram: 'Exp. histogram',
    summary: 'Summary',
};

/**
 * The order the picker groups types in: the levels a reader glances at first,
 * then counters, then the distributions.
 */
export const METRIC_TYPE_ORDER: MetricType[] = [
    'gauge',
    'sum',
    'histogram',
    'exponential_histogram',
    'summary',
];

/**
 * What the explorer's query can be changed by. `where` replaces the whole map
 * rather than merging into it, so removing a filter is the same call as adding
 * one.
 */
export type MetricQueryChanges = {
    project?: string | null;
    service?: string | null;
    metric?: string | null;
    where?: Record<string, string>;
    group_by?: string | null;
    agg?: string | null;
    from?: string | null;
    to?: string | null;
};

/**
 * The window a metric query should run over, given the toolbar's preset.
 *
 * The same rule the trace views follow: a preset is relative, so it is
 * resolved against the clock whenever a link is built; only a custom range
 * keeps the absolute bounds the filters arrived with.
 */
export function metricWindow(
    filters: Pick<MetricFilters, 'from' | 'to'>,
    range: LogRangePreset,
): { from: string; to: string } {
    const minutes = RANGE_PRESETS.find(
        (preset) => preset.value === range,
    )?.minutes;

    if (minutes === undefined) {
        return { from: filters.from, to: filters.to };
    }

    const to = new Date();

    return {
        from: new Date(to.getTime() - minutes * 60_000).toISOString(),
        to: to.toISOString(),
    };
}

/**
 * The query string a chart is described by.
 *
 * The URL is the state — a chart is a link someone can send — so it is built
 * in one place. `where` travels as `where[key]=value`, which PHP reads back as
 * an array without touching the dots inside the brackets; the default `avg` is
 * left out so the common link stays short.
 */
export function metricFilterQuery(
    filters: MetricFilters,
    range: LogRangePreset,
    changes: MetricQueryChanges = {},
): Record<string, string> {
    const { where: whereChange, ...scalarChanges } = changes;

    const merged: Record<string, string | null | undefined> = {
        project: filters.project,
        service: filters.service,
        metric: filters.metric,
        group_by: filters.groupBy,
        agg: filters.agg,
        ...metricWindow(filters, range),
        ...scalarChanges,
    };

    const where = whereChange ?? filters.where;
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(merged)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (key === 'agg' && value === DEFAULT_METRIC_AGGREGATION) {
            continue;
        }

        query[key] = value;
    }

    for (const [key, value] of Object.entries(where).slice(
        0,
        MAX_METRIC_FILTERS,
    )) {
        if (key === '' || value === '') {
            continue;
        }

        query[`where[${key}]`] = value;
    }

    return query;
}

/** A duration unit's size in milliseconds, or undefined for anything else. */
const DURATION_UNIT_MS: Record<string, number> = {
    s: 1_000,
    ms: 1,
    us: 0.001,
    ns: 0.000_001,
};

/** A byte unit's size in bytes. */
const BYTE_UNIT_SIZE: Record<string, number> = {
    By: 1,
    KiBy: 1024,
    MiBy: 1024 ** 2,
    GiBy: 1024 ** 3,
    kBy: 1_000,
    MBy: 1_000_000,
    GBy: 1_000_000_000,
};

const COMPACT_SUFFIXES = ['', 'k', 'M', 'B', 'T'];

/**
 * A number short enough for an axis label: 1.2k, 34M, 0.25.
 *
 * Three significant digits below a thousand, one decimal under a hundred of
 * a suffix, none above — the same precision ladder `formatBytes()` uses.
 */
export function formatCompactNumber(value: number): string {
    if (!Number.isFinite(value)) {
        return '—';
    }

    if (value < 0) {
        return `-${formatCompactNumber(-value)}`;
    }

    if (value < 1_000) {
        if (Number.isInteger(value)) {
            return String(value);
        }

        return String(Number(value.toPrecision(3)));
    }

    let scaled = value;
    let suffix = 0;

    // 999.95k would round to "1000k"; step up before that can happen.
    while (scaled >= 999.5 && suffix < COMPACT_SUFFIXES.length - 1) {
        scaled /= 1_000;
        suffix += 1;
    }

    const digits =
        scaled >= 100 ? String(Math.round(scaled)) : scaled.toFixed(1);

    return `${digits.replace(/\.0$/, '')}${COMPACT_SUFFIXES[suffix]}`;
}

/**
 * The noun a curly-brace annotation names: `{request}` → `request`.
 *
 * UCUM spells a dimensionless count as `{thing}`; the braces are notation, not
 * a unit, so a value is printed bare and the word goes on the axis instead.
 */
export function unitAnnotation(unit: string): string | null {
    const match = /^\{(.+)\}$/.exec(unit.trim());

    return match ? match[1] : null;
}

/**
 * Render one metric value in the unit the SDK declared.
 *
 * Durations reuse the trace formatter so a latency reads the same on every
 * surface; bytes go through `formatBytes()`; `1` is a ratio and `{thing}` a
 * count, both printed bare. A unit this does not recognise is appended as
 * sent rather than guessed at. `perSecond` is for a counter's rate.
 */
export function formatMetricValue(
    value: number | null,
    unit: string,
    perSecond = false,
): string {
    if (value === null || !Number.isFinite(value)) {
        return '—';
    }

    const rate = perSecond ? '/s' : '';
    const sign = value < 0 ? '-' : '';
    const magnitude = Math.abs(value);

    if (unit in DURATION_UNIT_MS) {
        return `${sign}${formatDuration(magnitude * DURATION_UNIT_MS[unit])}${rate}`;
    }

    if (unit in BYTE_UNIT_SIZE) {
        return `${sign}${formatBytes(magnitude * BYTE_UNIT_SIZE[unit])}${rate}`;
    }

    if (unit === '%') {
        return `${formatCompactNumber(value)}%${rate}`;
    }

    if (unit === '' || unit === '1' || unitAnnotation(unit) !== null) {
        return `${formatCompactNumber(value)}${rate}`;
    }

    return `${formatCompactNumber(value)} ${unit}${rate}`;
}

/** Pluralise an annotation noun the naive way, which is right for OTel's. */
function plural(noun: string): string {
    return noun.endsWith('s') ? noun : `${noun}s`;
}

/**
 * The y axis title: what the values measure, and per second for a rate.
 *
 * The tick labels already carry their own unit (`250 ms`, `1.2 MB`), so this
 * names the dimension rather than repeating a symbol — except for a count,
 * whose ticks are bare and whose noun only appears here.
 */
export function metricUnitLabel(
    unit: string,
    kind: MetricSeriesKind | null,
): string {
    const rate = kind === 'rate' ? '/s' : '';
    const annotation = unitAnnotation(unit);

    if (annotation !== null) {
        return `${plural(annotation)}${rate}`;
    }

    if (unit in DURATION_UNIT_MS) {
        return kind === 'rate' ? 'seconds/s' : 'duration';
    }

    if (unit in BYTE_UNIT_SIZE) {
        return `bytes${rate}`;
    }

    if (unit === '%') {
        return 'percent';
    }

    if (unit === '1') {
        return kind === 'rate' ? 'per second' : 'ratio';
    }

    if (unit === '') {
        return kind === 'rate' ? 'per second' : 'value';
    }

    return `${unit}${rate}`;
}

/**
 * One sentence on how the chart turns this metric into a line.
 *
 * The same numbers mean different things by type and temporality — a
 * cumulative counter's raw value is useless on a chart, an up-down counter's
 * is exactly what you want — so the page says which reading it chose.
 */
export function metricReading(entry: MetricCatalogEntry): string {
    switch (entry.type) {
        case 'gauge':
            return 'gauge — shown as a level';
        case 'sum':
            if (!entry.monotonic) {
                return 'up-down counter — shown as a level';
            }

            return entry.temporality === 1
                ? 'delta counter — shown as a rate'
                : 'cumulative counter — shown as a rate';
        case 'histogram':
        case 'exponential_histogram':
            return `${entry.type === 'histogram' ? 'histogram' : 'exponential histogram'} — shown as percentiles`;
        case 'summary':
            return 'summary — client-computed quantiles, averaged across series';
    }
}

/** Whether a result's lines are percentiles rather than one value per group. */
export function isPercentileKind(kind: MetricSeriesKind | null): boolean {
    return kind === 'distribution' || kind === 'summary';
}

/** Whether the chart was split by an attribute. */
export function isGroupedResult(result: MetricSeriesResult): boolean {
    return result.series.some((series) => series.group !== null);
}

/** The stats present in a result, in the order the server sent them. */
export function seriesStats(series: MetricSeries[]): string[] {
    return [...new Set(series.map((entry) => entry.stat))];
}

/**
 * The lines to draw.
 *
 * Only a *grouped* distribution is narrowed — to the chosen percentile, or to
 * the first stat the result has when that one is missing (a summary may carry
 * p90 and not p95). Everything else is drawn as sent.
 */
export function visibleSeries(
    result: MetricSeriesResult,
    percentile: string,
): MetricSeries[] {
    if (!isPercentileKind(result.kind) || !isGroupedResult(result)) {
        return result.series;
    }

    const stats = seriesStats(result.series);
    const stat = stats.includes(percentile) ? percentile : stats[0];

    return result.series.filter((entry) => entry.stat === stat);
}

/**
 * What a line is called in the legend and the tooltip.
 *
 * A grouped percentile line is named by its group alone — the stat is chosen
 * once, above the chart — and a series that lacks the attribute gets a name
 * that says so rather than an empty legend entry.
 */
export function seriesName(
    series: MetricSeries,
    percentileGrouped: boolean,
): string {
    if (percentileGrouped && series.group !== null) {
        return series.group === '' ? '(not set)' : series.group;
    }

    if (series.group === '') {
        return series.label || '(not set)';
    }

    return series.label;
}

/**
 * Catalog entries bucketed by type, in picker order, empty types dropped.
 */
export function catalogByType(
    metrics: MetricCatalogEntry[],
): { type: MetricType; label: string; metrics: MetricCatalogEntry[] }[] {
    return METRIC_TYPE_ORDER.map((type) => ({
        type,
        label: METRIC_TYPE_LABEL[type],
        metrics: metrics.filter((entry) => entry.type === type),
    })).filter((group) => group.metrics.length > 0);
}

const pad = (value: number) => String(value).padStart(2, '0');

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * Label a bucket at the coarsest precision that still tells buckets apart —
 * a date for day buckets, minutes above a minute, seconds below. Local time
 * by default, like every other chart; `utc` for the tooltip's second clock.
 */
export function bucketLabel(
    date: Date,
    intervalSeconds: number,
    utc = false,
): string {
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const month = utc ? date.getUTCMonth() : date.getMonth();
    const day = utc ? date.getUTCDate() : date.getDate();
    const hours = utc ? date.getUTCHours() : date.getHours();
    const minutes = utc ? date.getUTCMinutes() : date.getMinutes();
    const seconds = utc ? date.getUTCSeconds() : date.getSeconds();

    if (intervalSeconds >= 86_400) {
        return `${MONTHS[month]} ${day}`;
    }

    if (intervalSeconds >= 60) {
        return `${pad(hours)}:${pad(minutes)}`;
    }

    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

/**
 * Escape text for an ECharts tooltip, which is rendered as HTML.
 *
 * Group names are attribute values an exporter sent — a route, a user agent —
 * so they are untrusted and must never reach `innerHTML` as markup.
 */
export function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}
