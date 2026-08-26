import type { LogEntry, LogRangePreset, SeverityLevel } from '@/types';

export const SEVERITY_LEVELS: SeverityLevel[] = [
    'trace',
    'debug',
    'info',
    'warn',
    'error',
    'fatal',
];

/**
 * The text colour utility for each severity bucket, defined in app.css.
 */
export const SEVERITY_TEXT_CLASS: Record<SeverityLevel, string> = {
    trace: 'text-severity-trace',
    debug: 'text-severity-debug',
    info: 'text-severity-info',
    warn: 'text-severity-warn',
    error: 'text-severity-error',
    fatal: 'text-severity-fatal',
};

/**
 * The background colour utility for each severity bucket, defined in app.css.
 */
export const SEVERITY_DOT_CLASS: Record<SeverityLevel, string> = {
    trace: 'bg-severity-trace',
    debug: 'bg-severity-debug',
    info: 'bg-severity-info',
    warn: 'bg-severity-warn',
    error: 'bg-severity-error',
    fatal: 'bg-severity-fatal',
};

/**
 * The CSS custom property holding each severity colour, defined in app.css.
 *
 * Charts cannot use the utility classes, so they read these variables off the
 * root element instead — see `readChartTokens()` in resources/js/lib/echarts.ts.
 */
export const SEVERITY_CSS_VARIABLE: Record<SeverityLevel, string> = {
    trace: '--severity-trace',
    debug: '--severity-debug',
    info: '--severity-info',
    warn: '--severity-warn',
    error: '--severity-error',
    fatal: '--severity-fatal',
};

export const RANGE_PRESETS: {
    value: Exclude<LogRangePreset, 'custom'>;
    label: string;
    minutes: number;
}[] = [
    { value: '15m', label: 'Last 15 minutes', minutes: 15 },
    { value: '1h', label: 'Last hour', minutes: 60 },
    { value: '6h', label: 'Last 6 hours', minutes: 360 },
    { value: '24h', label: 'Last 24 hours', minutes: 1440 },
    { value: '7d', label: 'Last 7 days', minutes: 10080 },
];

/**
 * Resolve the severity bucket an entry belongs to.
 */
export function severityLevelFor(entry: LogEntry): SeverityLevel {
    if (entry.severityNumber >= 1 && entry.severityNumber <= 24) {
        return SEVERITY_LEVELS[
            Math.min(5, Math.floor((entry.severityNumber - 1) / 4))
        ];
    }

    const text = entry.severityText.toLowerCase();

    if (text.startsWith('warn')) {
        return 'warn';
    }

    if (text.startsWith('err')) {
        return 'error';
    }

    if (text.startsWith('crit') || text.startsWith('fatal')) {
        return 'fatal';
    }

    const matched = SEVERITY_LEVELS.find((level) => text.startsWith(level));

    return matched ?? 'info';
}

/**
 * Work out which preset the given window corresponds to, if any.
 */
export function presetForRange(from: string, to: string): LogRangePreset {
    const fromTime = Date.parse(from);
    const toTime = Date.parse(to);

    if (Number.isNaN(fromTime) || Number.isNaN(toTime)) {
        return 'custom';
    }

    // Anything that does not end at (roughly) "now" is a custom window.
    if (Math.abs(Date.now() - toTime) > 5 * 60_000) {
        return 'custom';
    }

    const minutes = Math.round((toTime - fromTime) / 60_000);

    return (
        RANGE_PRESETS.find((preset) => Math.abs(preset.minutes - minutes) <= 1)
            ?.value ?? 'custom'
    );
}

/**
 * Parse a ClickHouse `DateTime64` value, which arrives as a naive UTC string.
 */
export function parseTimestamp(timestamp: string): Date {
    const normalized = timestamp.includes('T')
        ? timestamp
        : `${timestamp.replace(' ', 'T')}Z`;

    return new Date(normalized);
}

/**
 * Format a log timestamp for the list, keeping millisecond precision.
 */
export function formatTimestamp(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return date.toISOString().replace('T', ' ').replace('Z', '');
}
