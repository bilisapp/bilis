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
 * The left-edge hairline colour for each severity bucket.
 *
 * Every row carries one, so the left edge of the stream reads as a continuous
 * temperature ribbon: a burst of red is visible before a single line is read.
 */
export const SEVERITY_EDGE_CLASS: Record<SeverityLevel, string> = {
    trace: 'border-l-severity-trace',
    debug: 'border-l-severity-debug',
    info: 'border-l-severity-info',
    warn: 'border-l-severity-warn',
    error: 'border-l-severity-error',
    fatal: 'border-l-severity-fatal',
};

/**
 * The resting tint for a row, reserved for the levels that mean something broke.
 *
 * Quiet levels stay on the card so the loud ones carry all the weight — if
 * every row were tinted, none of them would read as urgent.
 */
export const SEVERITY_ROW_CLASS: Record<SeverityLevel, string> = {
    trace: '',
    debug: '',
    info: '',
    warn: 'bg-severity-warn/[0.05] dark:bg-severity-warn/[0.08]',
    error: 'bg-severity-error/[0.06] dark:bg-severity-error/[0.10]',
    fatal: 'bg-severity-fatal/[0.09] dark:bg-severity-fatal/[0.14]',
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
 * The window the viewer opens on, matching `LogFilters::DEFAULT_RANGE_MINUTES`
 * on the server. Resetting the filters returns here; change both or neither.
 */
export const DEFAULT_RANGE_PRESET: LogRangePreset = '1h';

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
 * The timezone the reader's browser is in, or UTC when it will not say.
 *
 * Everything on the wire is naive UTC — filters, cursors, the stored column
 * (see .ai/rules/click-house.md). This is display only: it never travels back
 * to the server, and no formatter here is allowed to change what is sent.
 */
export function browserTimeZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

/**
 * Break a date into padded calendar parts in the given zone.
 *
 * `Intl` does the work rather than arithmetic on `getTimezoneOffset()`: only
 * the formatter knows that a timestamp from last winter belongs to a
 * different offset than one from today.
 *
 * `hourCycle: 'h23'` and not `hour12: false` — the latter renders midnight as
 * "24" in some engines, which would read as an hour that does not exist.
 */
function calendarParts(date: Date, timeZone: string): Record<string, string> {
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });

    const parts: Record<string, string> = {};

    for (const part of formatter.formatToParts(date)) {
        parts[part.type] = part.value;
    }

    return parts;
}

/**
 * Render an instant as `YYYY-MM-DD HH:MM:SS.mmm` in the given zone.
 *
 * Milliseconds come off the Date itself: no timezone in use shifts an instant
 * by a fraction of a second, so there is nothing for the formatter to say
 * about them.
 */
function formatInZone(date: Date, timeZone: string): string {
    const parts = calendarParts(date, timeZone);
    const milliseconds = String(date.getMilliseconds()).padStart(3, '0');

    return (
        `${parts.year}-${parts.month}-${parts.day} ` +
        `${parts.hour}:${parts.minute}:${parts.second}.${milliseconds}`
    );
}

/**
 * Format a log timestamp for the list, keeping millisecond precision.
 *
 * Rendered in the reader's own timezone: a log line is read against the clock
 * on the wall, not against the clock the server keeps. The exact UTC value is
 * never lost — every surface that shows this puts `formatUtcTimestamp()` in a
 * title attribute beside it.
 */
export function formatTimestamp(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return formatInZone(date, browserTimeZone());
}

/**
 * The same instant in UTC, said out loud — what hover and tooltips carry.
 *
 * This is the value that matches the stored column, the filter parameters and
 * anything a reader might paste into a query, so it is always one hover away.
 */
export function formatUtcTimestamp(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    return `${formatInZone(date, 'UTC')} UTC`;
}

/**
 * A chart's `HH:MM` label for a bucket, in the reader's timezone.
 */
export function formatHourLabel(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const parts = calendarParts(date, browserTimeZone());

    return `${parts.hour}:${parts.minute}`;
}

/**
 * The same bucket label in UTC, for the tooltip line that sits beside it.
 */
export function formatUtcHourLabel(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const parts = calendarParts(date, 'UTC');

    return `${parts.hour}:${parts.minute} UTC`;
}

/**
 * The active timezone as a short label: `Europe/Bratislava (UTC+02:00)`.
 *
 * The offset is read out of a formatted part rather than computed, so it is
 * the offset in force right now — DST included — and UTC says only "UTC"
 * rather than the tautological "UTC (UTC+00:00)".
 */
export function timeZoneLabel(): string {
    const zone = browserTimeZone();

    if (zone === 'UTC') {
        return 'UTC';
    }

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: zone,
            timeZoneName: 'longOffset',
        }).formatToParts(new Date());

        const offset = parts.find(
            (part) => part.type === 'timeZoneName',
        )?.value;

        // Intl says "GMT+02:00"; this product says UTC everywhere else.
        return offset ? `${zone} (${offset.replace('GMT', 'UTC')})` : zone;
    } catch {
        return zone;
    }
}

/**
 * The offset in force for one specific instant: `+02:00`, or `UTC` at home.
 *
 * Per-instant rather than per-session, because a list of rows can straddle a
 * DST switch: the rows before the change honestly wear a different offset
 * than the rows after it.
 */
export function timeZoneOffset(timestamp: string): string {
    const zone = browserTimeZone();

    if (zone === 'UTC') {
        return 'UTC';
    }

    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: zone,
            timeZoneName: 'longOffset',
        }).formatToParts(date);

        const offset = parts.find(
            (part) => part.type === 'timeZoneName',
        )?.value;

        // Intl says "GMT+02:00"; the row needs only the bare offset.
        return offset ? offset.replace('GMT', '') || 'UTC' : '';
    } catch {
        return '';
    }
}

/**
 * The one sentence every surface uses to state which clock it is showing.
 *
 * In UTC there is nothing to reconcile and nothing to hover for, so the
 * notice collapses to the shortest true thing it can say.
 */
export function timeZoneNotice(): string {
    const zone = browserTimeZone();

    return zone === 'UTC'
        ? 'times in UTC'
        : `times shown in ${timeZoneLabel()} · hover for UTC`;
}

/**
 * Render a byte count the way humans read disk usage (1024-based).
 */
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes < 0) {
        return '0 B';
    }

    if (bytes < 1024) {
        return `${Math.round(bytes)} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
    let value = bytes;
    let unit = -1;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value >= 100 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

/**
 * Render how long ago a timestamp was, in the coarsest unit that still reads
 * as an answer ("4m ago", "3h ago"). Used for service liveness, where the
 * question is "is it still shipping", not "at which millisecond".
 *
 * Timezone-free by construction: it subtracts two instants, and an instant is
 * the same number of milliseconds whichever clock is looking at it.
 */
export function formatRelativeTime(timestamp: string): string {
    const date = parseTimestamp(timestamp);

    if (Number.isNaN(date.getTime())) {
        return timestamp;
    }

    const seconds = Math.round((Date.now() - date.getTime()) / 1000);

    if (seconds < 0) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.floor(hours / 24)}d ago`;
}
