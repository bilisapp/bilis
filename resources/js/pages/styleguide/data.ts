import type { LogEntry, SeverityLevel } from '@/types';

export type Swatch = {
    /** Human readable name of the colour or token. */
    name: string;
    /** Utility classes applied to the preview block. */
    className: string;
    /** The utility a developer would reach for, shown as a label. */
    label: string;
    /** Source hex, only known for the static brand palette. */
    hex?: string;
    /** Where the colour is meant to be used. */
    note?: string;
    /** Text rendered inside the preview block. */
    preview?: string;
};

export type StyleguideSection = {
    id: string;
    title: string;
};

export const STYLEGUIDE_SECTIONS: StyleguideSection[] = [
    { id: 'palette', title: 'Brand palette' },
    { id: 'tokens', title: 'Semantic tokens' },
    { id: 'severity', title: 'Severity scale' },
    { id: 'typography', title: 'Typography' },
    { id: 'components', title: 'Components' },
    { id: 'app-components', title: 'App components' },
    { id: 'charts', title: 'Charts' },
];

/**
 * The static brand palette, derived from the mid-century stripes artwork.
 */
export const BRAND_SWATCHES: Swatch[] = [
    {
        name: 'Cream',
        hex: '#f3f0e7',
        className: 'bg-cream',
        label: 'bg-cream',
        note: 'Light mode page surface, the paper the product sits on.',
    },
    {
        name: 'Greige',
        hex: '#dbd5c3',
        className: 'bg-greige',
        label: 'bg-greige',
        note: 'Secondary surfaces, borders and inset panels in light mode.',
    },
    {
        name: 'Espresso',
        hex: '#463828',
        className: 'bg-espresso',
        label: 'bg-espresso',
        note: 'Ink in light mode, page and card surfaces in dark mode.',
    },
    {
        name: 'Navy',
        hex: '#1f3a5f',
        className: 'bg-navy',
        label: 'bg-navy',
        note: 'Light mode brand and primary: buttons, focus rings, info.',
    },
    {
        name: 'Gold',
        hex: '#f3c440',
        className: 'bg-gold',
        label: 'bg-gold',
        note: 'Dark mode primary, and the warning accent in both modes.',
    },
    {
        name: 'Crimson',
        hex: '#d8394a',
        className: 'bg-crimson',
        label: 'bg-crimson',
        note: 'Destructive actions, error severity and failed ingest states.',
    },
    {
        name: 'Teal',
        hex: '#45bfa6',
        className: 'bg-teal',
        label: 'bg-teal',
        note: 'Success and healthy-state accents, debug severity.',
    },
    {
        name: 'Aqua',
        hex: '#abdcd2',
        className: 'bg-aqua',
        label: 'bg-aqua',
        note: 'Soft informational accent, chart fills and empty states.',
    },
    {
        name: 'Blush',
        hex: '#f3b9b3',
        className: 'bg-blush',
        label: 'bg-blush',
        note: 'Soft accent for highlights that must not read as an error.',
    },
];

/**
 * Semantic shadcn tokens. Rendered through their utilities so that they
 * invert with the active colour scheme.
 */
export const TOKEN_SWATCHES: Swatch[] = [
    {
        name: 'Background / Foreground',
        className: 'bg-background text-foreground',
        label: 'bg-background text-foreground',
        preview: 'Aa',
        note: 'Cream page with espresso ink; espresso page with cream ink.',
    },
    {
        name: 'Card',
        className: 'bg-card text-card-foreground',
        label: 'bg-card text-card-foreground',
        preview: 'Aa',
        note: 'Panels lifted a shade off the page in both modes.',
    },
    {
        name: 'Primary',
        className: 'bg-primary text-primary-foreground',
        label: 'bg-primary text-primary-foreground',
        preview: 'Aa',
        note: 'Navy in light mode, gold in dark mode.',
    },
    {
        name: 'Secondary',
        className: 'bg-secondary text-secondary-foreground',
        label: 'bg-secondary text-secondary-foreground',
        preview: 'Aa',
        note: 'Greige chips and quiet buttons.',
    },
    {
        name: 'Muted',
        className: 'bg-muted text-muted-foreground',
        label: 'bg-muted text-muted-foreground',
        preview: 'Aa',
        note: 'Timestamps, metadata and disabled copy.',
    },
    {
        name: 'Accent',
        className: 'bg-accent text-accent-foreground',
        label: 'bg-accent text-accent-foreground',
        preview: 'Aa',
        note: 'Hover and active states for rows, menus and severity chips.',
    },
    {
        name: 'Destructive',
        className: 'bg-destructive text-destructive-foreground',
        label: 'bg-destructive text-destructive-foreground',
        preview: 'Aa',
        note: 'Crimson: delete team, revoke API key, error alerts.',
    },
    {
        name: 'Popover',
        className: 'bg-popover text-popover-foreground',
        label: 'bg-popover text-popover-foreground',
        preview: 'Aa',
        note: 'Dropdowns, selects and tooltips floating above the page.',
    },
    {
        name: 'Border',
        className: 'border-8 border-border bg-card text-card-foreground',
        label: 'border-border',
        preview: 'Aa',
        note: 'Hairlines between log rows, cards and toolbars.',
    },
    {
        name: 'Input',
        className: 'border-8 border-input bg-card text-card-foreground',
        label: 'border-input',
        preview: 'Aa',
        note: 'Field outlines, a touch darker than the plain border.',
    },
    {
        name: 'Ring',
        className: 'bg-card text-card-foreground ring-4 ring-ring ring-inset',
        label: 'ring-ring',
        preview: 'Aa',
        note: 'Keyboard focus. Navy in light mode, gold in dark mode.',
    },
    {
        name: 'Sidebar',
        className: 'bg-sidebar text-sidebar-foreground',
        label: 'bg-sidebar text-sidebar-foreground',
        preview: 'Aa',
        note: 'The navigation rail, one step deeper than the page.',
    },
];

/**
 * Chart series colours. These are defined per colour scheme, so the
 * swatches below intentionally change between light and dark.
 */
export const CHART_SWATCHES: Swatch[] = [
    {
        name: 'Chart 1',
        className: 'bg-chart-1',
        label: 'bg-chart-1',
        note: 'Primary series. Gold in both modes.',
    },
    {
        name: 'Chart 2',
        className: 'bg-chart-2',
        label: 'bg-chart-2',
        note: 'Teal. Healthy throughput and info volume.',
    },
    {
        name: 'Chart 3',
        className: 'bg-chart-3',
        label: 'bg-chart-3',
        note: 'Navy, lightened in dark mode so it stays legible.',
    },
    {
        name: 'Chart 4',
        className: 'bg-chart-4',
        label: 'bg-chart-4',
        note: 'Crimson. Error rate lines and failure bars.',
    },
    {
        name: 'Chart 5',
        className: 'bg-chart-5',
        label: 'bg-chart-5',
        note: 'Espresso in light mode, blush in dark mode.',
    },
];

/**
 * Usage notes for each severity bucket, keyed by level.
 */
export const SEVERITY_NOTES: Record<SeverityLevel, string> = {
    trace: 'Span-level noise. Muted so it never competes with real signal.',
    debug: 'Developer detail. Teal, readable but calm.',
    info: 'The default. Navy in light mode, lifted blue in dark mode.',
    warn: 'Gold. Something is off but the request still succeeded.',
    error: 'Crimson. A request or job failed.',
    fatal: 'Deep crimson. The process went down.',
};

const demoBodies: Record<SeverityLevel, string> = {
    trace: 'span checkout.charge started attempt=1',
    debug: 'resolved 42 log rows from ClickHouse in 18ms',
    info: 'POST /api/v1/logs 202 project=checkout-api batch=128',
    warn: 'ingest batch throttled, retrying in 250ms queue_depth=4096',
    error: 'failed to flush batch to ClickHouse: connection reset by peer',
    fatal: 'ingest worker exited: cannot allocate buffer for 1.2GB batch',
};

const demoServices: Record<SeverityLevel, string> = {
    trace: 'checkout-api',
    debug: 'bilis-query',
    info: 'checkout-api',
    warn: 'bilis-ingest',
    error: 'bilis-ingest',
    fatal: 'bilis-ingest',
};

const severityNumbers: Record<SeverityLevel, number> = {
    trace: 1,
    debug: 5,
    info: 9,
    warn: 13,
    error: 17,
    fatal: 21,
};

/**
 * Build a demo log entry for the given severity bucket.
 */
export function demoLogEntry(
    level: SeverityLevel,
    minutesAgo: number,
): LogEntry {
    const timestamp = new Date(
        Date.UTC(2026, 7, 26, 9, 14, 0) - minutesAgo * 60_000,
    )
        .toISOString()
        .replace('T', ' ')
        .replace('Z', '');

    return {
        projectId: 1,
        timestamp,
        traceId: '4f2a9c1e7b3d48a5b6c0d1e2f3a4b5c6',
        spanId: 'a1b2c3d4e5f60718',
        severityText: level.toUpperCase(),
        severityNumber: severityNumbers[level],
        serviceName: demoServices[level],
        body: demoBodies[level],
        scopeName: 'bilis.ingest',
        scopeVersion: '0.4.1',
        resourceAttributes: {
            'deployment.environment': 'production',
            'host.name': 'ingest-worker-03',
        },
        logAttributes: {
            'http.route': '/api/v1/logs',
            'http.status_code': '202',
            'batch.size': '128',
        },
    };
}

/**
 * Demo log volume for the charts section: the last seven days, bucketed by
 * severity, roughly shaped like a real week of ingest (quiet weekend, a
 * Thursday incident).
 */
export const CHART_VOLUME_DAYS: string[] = [
    'Aug 20',
    'Aug 21',
    'Aug 22',
    'Aug 23',
    'Aug 24',
    'Aug 25',
    'Aug 26',
];

export const CHART_VOLUME_BY_SEVERITY: Record<SeverityLevel, number[]> = {
    trace: [4200, 4400, 4100, 1800, 1600, 4600, 4900],
    debug: [2600, 2700, 2500, 1100, 1000, 2900, 3100],
    info: [8100, 8400, 7900, 3400, 3200, 8800, 9300],
    warn: [420, 460, 1240, 380, 310, 520, 610],
    error: [130, 150, 780, 90, 70, 180, 240],
    fatal: [2, 1, 34, 0, 0, 3, 6],
};

/**
 * Demo ingest rate for the charts section: accepted log records per second,
 * per project, over a twelve hour window.
 */
export const CHART_INGEST_HOURS: string[] = [
    '00:00',
    '02:00',
    '04:00',
    '06:00',
    '08:00',
    '10:00',
    '12:00',
    '14:00',
    '16:00',
    '18:00',
    '20:00',
    '22:00',
];

export const CHART_INGEST_SERIES: { name: string; values: number[] }[] = [
    {
        name: 'checkout-api',
        values: [180, 140, 120, 210, 640, 910, 980, 940, 870, 720, 480, 260],
    },
    {
        name: 'billing-worker',
        values: [90, 80, 70, 110, 240, 320, 360, 380, 340, 280, 190, 120],
    },
    {
        name: 'edge-proxy',
        values: [
            420, 380, 350, 470, 820, 1120, 1180, 1150, 1040, 880, 640, 480,
        ],
    },
    {
        name: 'search-indexer',
        values: [60, 55, 210, 240, 190, 160, 150, 320, 480, 260, 110, 70],
    },
    {
        name: 'notifications',
        values: [30, 25, 20, 45, 130, 210, 240, 230, 200, 170, 90, 50],
    },
];
