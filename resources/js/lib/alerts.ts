import { formatDuration } from '@/lib/traces';
import type {
    AlertCompare,
    AlertInstanceSummary,
    AlertCondition,
    AlertEventType,
    AlertInstanceState,
    AlertLabels,
    AlertPreviewSeries,
    AlertQuery,
    AlertRuleShape,
    AlertSeverity,
    AlertSourceKey,
    NoDataState,
    NotificationChannelType,
} from '@/types';

/**
 * Alerting, as words: what a rule watches, how long it waits, what a value
 * means. Every surface that prints a rule — the list, a firing series, the
 * history, the editor's summary line — goes through here, so "p95 > 500 ms
 * for 5m" reads the same everywhere.
 */

export const ALERT_SOURCE_LABEL: Record<AlertSourceKey, string> = {
    logs: 'Logs',
    traces: 'Traces',
    metrics: 'Metrics',
};

export const ALERT_STATE_LABEL: Record<AlertInstanceState, string> = {
    firing: 'Firing',
    pending: 'Pending',
    inactive: 'OK',
};

export const ALERT_SEVERITY_LABEL: Record<AlertSeverity, string> = {
    info: 'Info',
    warning: 'Warning',
    critical: 'Critical',
};

export const ALERT_EVENT_LABEL: Record<AlertEventType, string> = {
    firing: 'Firing',
    resolved: 'Resolved',
    rule_error: 'Rule error',
    issue_new: 'New issue',
    issue_regressed: 'Regressed issue',
};

export const NO_DATA_LABEL: Record<NoDataState, string> = {
    inactive: 'Treat as OK',
    firing: 'Fire',
    keep_last: 'Keep the last state',
};

export const CHANNEL_TYPE_LABEL: Record<NotificationChannelType, string> = {
    email: 'Email',
    slack: 'Slack',
    webhook: 'Webhook',
};

/** What each aggregation is called on screen. */
export const AGGREGATION_LABEL: Record<string, string> = {
    count: 'count',
    rate: 'rate',
    ratio: 'ratio',
    error_ratio: 'error ratio',
    avg: 'avg',
    max: 'max',
    min: 'min',
    last: 'last',
    p50: 'p50',
    p95: 'p95',
    p99: 'p99',
};

/** The windows the editor offers; any value from 1m to 24h is valid. */
export const WINDOW_PRESETS = [60, 300, 600, 900, 1800, 3600, 21600, 86400];

export const HOLD_PRESETS = [0, 60, 120, 300, 600, 900, 1800, 3600];

export const MUTE_OPTIONS: { value: '1h' | '1d' | '1w'; label: string }[] = [
    { value: '1h', label: 'For 1 hour' },
    { value: '1d', label: 'For 1 day' },
    { value: '1w', label: 'For 1 week' },
];

/**
 * A span of seconds as a rule says it: `30s`, `5m`, `1h`, `1h 30m`, `1d`.
 * Zero is `0s` — "fire immediately" is a choice, not a blank.
 */
export function formatSeconds(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '—';
    }

    const whole = Math.round(seconds);

    if (whole < 60) {
        return `${whole}s`;
    }

    const days = Math.floor(whole / 86400);
    const hours = Math.floor((whole % 86400) / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const rest = whole % 60;

    return [
        days ? `${days}d` : '',
        hours ? `${hours}h` : '',
        minutes ? `${minutes}m` : '',
        rest ? `${rest}s` : '',
    ]
        .filter(Boolean)
        .join(' ');
}

/** The baselines a log or trace rule can compare with, as the editor offers them. */
export const COMPARE_OFFSETS: {
    value: number;
    label: string;
    short: string;
}[] = [
    { value: 86400, label: 'vs. same time yesterday', short: 'yesterday' },
    { value: 604800, label: 'vs. same time last week', short: 'last week' },
];

/** Which aggregations a baseline comparison applies to (`LogAlertSource`, `TraceAlertSource`). */
export function canCompare(source: string, aggregation: string): boolean {
    return (
        (source === 'logs' && ['count', 'rate'].includes(aggregation)) ||
        source === 'traces'
    );
}

/** `last week`, `yesterday`, or the offset itself for anything else. */
export function compareLabel(compare: AlertCompare): string {
    return (
        COMPARE_OFFSETS.find(
            (option) => option.value === compare.offset_seconds,
        )?.short ?? `${formatSeconds(compare.offset_seconds)} earlier`
    );
}

/** Whether a value of this aggregation is a share between 0 and 1. */
export function isRatio(source: string, aggregation: string): boolean {
    return (
        (source === 'logs' && aggregation === 'ratio') ||
        (source === 'traces' && aggregation === 'error_ratio')
    );
}

/**
 * Whether a metric is an OTel `*.utilization` gauge — a share from 0 to 1 by
 * semantic convention (`system.filesystem.utilization`), so 0.93 reads `93%`.
 */
export function isUtilization(
    source: string,
    metric: string | null | undefined,
): boolean {
    return source === 'metrics' && !!metric && metric.endsWith('.utilization');
}

/** Whether a value of this aggregation is a span duration in milliseconds. */
export function isDuration(source: string, aggregation: string): boolean {
    return source === 'traces' && /^p\d+$/.test(aggregation);
}

/**
 * A rule's value, in the unit its aggregation produces: a count as a count,
 * a log rate per minute, a ratio as a percentage, a span percentile as a
 * duration, a metric as a plain number.
 */
export function formatAlertValue(
    value: number | null | undefined,
    source: string,
    aggregation: string,
    compare?: AlertCompare | null,
    metric?: string | null,
): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }

    // A compared rule's value is a multiple of its baseline, whatever it reads.
    if (compare) {
        return `${formatNumber(value)}× normal`;
    }

    if (isRatio(source, aggregation) || isUtilization(source, metric)) {
        const percent = value * 100;

        return `${Number(percent.toFixed(percent !== 0 && Math.abs(percent) < 1 ? 2 : 1))}%`;
    }

    if (isDuration(source, aggregation)) {
        return formatDuration(value);
    }

    if (source === 'logs' && aggregation === 'rate') {
        return `${formatNumber(value)}/min`;
    }

    return formatNumber(value);
}

/**
 * A plain number without float noise: integers grouped, fractions to at most
 * four significant decimals.
 */
export function formatNumber(value: number): string {
    if (!Number.isFinite(value)) {
        return '—';
    }

    if (Number.isInteger(value)) {
        return value.toLocaleString('en-US');
    }

    const magnitude = Math.abs(value);
    const digits = magnitude >= 100 ? 1 : magnitude >= 1 ? 2 : 4;

    return Number(value.toFixed(digits)).toLocaleString('en-US', {
        maximumFractionDigits: digits,
    });
}

/**
 * What the rule measures, before the comparison: `p95 duration`,
 * `count of error, fatal logs from checkout`, `avg system.cpu.utilization`.
 */
export function describeQuery(source: string, query: AlertQuery): string {
    const aggregation =
        AGGREGATION_LABEL[query.aggregation] ?? query.aggregation;
    const filters = (query.filters ?? {}) as Record<string, unknown>;
    const services = Array.isArray(filters.services)
        ? (filters.services as string[])
        : [];
    const from = services.length ? ` from ${services.join(', ')}` : '';

    if (source === 'logs') {
        const severities = Array.isArray(filters.severities)
            ? (filters.severities as string[])
            : [];
        const search =
            typeof filters.search === 'string' && filters.search.trim() !== ''
                ? ` matching “${filters.search.trim()}”`
                : '';
        const what = `${severities.length ? `${severities.join(', ')} ` : ''}logs`;

        return `${aggregation} of ${what}${from}${search}`;
    }

    if (source === 'traces') {
        const kind =
            typeof filters.kind === 'string' && filters.kind
                ? `${filters.kind.toLowerCase()} `
                : '';
        const name =
            typeof filters.span_name === 'string' && filters.span_name
                ? ` named ${filters.span_name}`
                : '';
        const subject = isDuration('traces', query.aggregation)
            ? `${aggregation} of ${kind}span duration`
            : `${aggregation} of ${kind}spans`;

        return `${subject}${name}${from}`;
    }

    const where = Object.entries(filters)
        .filter(([, value]) => typeof value === 'string' && value !== '')
        .map(([key, value]) => `${key}=${String(value)}`);

    return `${aggregation} ${query.metric ?? 'metric'}${where.length ? ` where ${where.join(', ')}` : ''}`;
}

/**
 * The condition as one line: `p95 > 500 ms for 5m`, or for a compared rule
 * with a volume guard `count > 3× last week (min 20)`. The `for` is left out
 * when it is zero, since "for 0s" is just "when".
 */
export function formatCondition(rule: AlertRuleShape): string {
    const aggregation =
        AGGREGATION_LABEL[rule.query.aggregation] ?? rule.query.aggregation;
    const compare = rule.query.compare;
    const threshold = compare
        ? `${formatNumber(rule.threshold)}× ${compareLabel(compare)}`
        : formatAlertValue(
              rule.threshold,
              rule.source,
              rule.query.aggregation,
              null,
              rule.query.metric,
          );
    const hold =
        rule.forSeconds && rule.forSeconds > 0
            ? ` for ${formatSeconds(rule.forSeconds)}`
            : '';
    const minimum =
        rule.query.min_events && rule.query.min_events > 0
            ? ` (min ${formatNumber(rule.query.min_events)})`
            : '';

    return `${aggregation} ${rule.condition} ${threshold}${hold}${minimum}`;
}

/** Whether a value crosses the threshold the way the rule's condition says. */
export function conditionHolds(
    value: number | null,
    condition: AlertCondition,
    threshold: number,
): boolean {
    if (value === null || !Number.isFinite(value)) {
        return false;
    }

    switch (condition) {
        case '>':
            return value > threshold;
        case '>=':
            return value >= threshold;
        case '<':
            return value < threshold;
        case '<=':
            return value <= threshold;
    }
}

/**
 * A series' labels as `key=value` pairs, in key order so two series with
 * the same labels always read alike. An ungrouped rule's one series has
 * none and says so.
 */
export function formatLabels(labels: AlertLabels | null | undefined): string {
    const entries = Object.entries(labels ?? {}).sort(([a], [b]) =>
        a.localeCompare(b),
    );

    if (entries.length === 0) {
        return 'all series';
    }

    return entries.map(([key, value]) => `${key}=${value}`).join(', ');
}

/** A preview line's legend name: its labels' values, or the rule's own name. */
export function previewSeriesName(
    series: AlertPreviewSeries,
    fallback = 'value',
): string {
    const values = Object.values(series.labels ?? {});

    return values.length ? values.join(' · ') : fallback;
}

/**
 * The index ranges of points where the condition holds in at least one
 * series, merged, as `[start, end]` bucket indexes — what the preview shades.
 */
export function breachRanges(
    series: AlertPreviewSeries[],
    condition: AlertCondition,
    threshold: number,
): [number, number][] {
    const length = Math.max(0, ...series.map((line) => line.points.length));
    const ranges: [number, number][] = [];
    let start: number | null = null;

    for (let index = 0; index < length; index++) {
        const breached = series.some((line) =>
            conditionHolds(
                line.points[index]?.[1] ?? null,
                condition,
                threshold,
            ),
        );

        if (breached && start === null) {
            start = index;
        }

        if (!breached && start !== null) {
            ranges.push([start, index - 1]);
            start = null;
        }
    }

    if (start !== null) {
        ranges.push([start, length - 1]);
    }

    return ranges;
}

/** How long ago a state began, for "firing for 12m". */
export function sinceSeconds(
    iso: string | null,
    now = Date.now(),
): number | null {
    if (!iso) {
        return null;
    }

    const at = Date.parse(iso);

    return Number.isNaN(at) ? null : Math.max(0, Math.round((now - at) / 1000));
}

/**
 * The threshold's unit hint in the editor: what to type for this aggregation.
 */
export function thresholdHint(
    source: string,
    aggregation: string,
    compare?: AlertCompare | null,
): string {
    if (compare) {
        return `A multiple of the same window ${compareLabel(compare)} — 3 is 3× normal.`;
    }

    if (isRatio(source, aggregation)) {
        return 'A share from 0 to 1 — 0.05 is 5%.';
    }

    if (isDuration(source, aggregation)) {
        return 'Milliseconds.';
    }

    if (source === 'logs' && aggregation === 'rate') {
        return 'Matching lines per minute.';
    }

    if (aggregation === 'count') {
        return `Matching ${source === 'logs' ? 'lines' : 'spans'} in the window.`;
    }

    return 'In the metric’s own unit.';
}

/**
 * A series' reading against its rule, in words: `4.2× normal` against
 * `> 3× last week`, `93%` against `> 90%`, `p95 812 ms` against `> 500 ms`.
 * A percentile names itself, since "812 ms" alone does not say of what. A
 * series firing on no data says so rather than printing a dash.
 */
export function formatReading(
    instance: Pick<
        AlertInstanceSummary,
        'value' | 'source' | 'query' | 'condition' | 'threshold'
    >,
): { value: string; threshold: string } {
    const { source, query } = instance;
    const compare = query.compare ?? null;
    const prefix =
        !compare && isDuration(source, query.aggregation)
            ? `${AGGREGATION_LABEL[query.aggregation] ?? query.aggregation} `
            : '';
    const value =
        instance.value === null || !Number.isFinite(instance.value)
            ? 'no data'
            : `${prefix}${formatAlertValue(instance.value, source, query.aggregation, compare, query.metric)}`;
    const threshold = compare
        ? `${formatNumber(instance.threshold)}× ${compareLabel(compare)}`
        : formatAlertValue(
              instance.threshold,
              source,
              query.aggregation,
              null,
              query.metric,
          );

    return { value, threshold: `${instance.condition} ${threshold}` };
}
