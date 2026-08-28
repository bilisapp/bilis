import type {
    FixJobEvent,
    FixJobStatus,
    IngestRateUsage,
    LogEntry,
    LogHistogram,
    ProjectRepository,
    SeverityLevel,
    TeamLlmCredential,
} from '@/types';

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
    { id: 'surfaces', title: 'Neutral ladder' },
    { id: 'tokens', title: 'Semantic tokens' },
    { id: 'severity', title: 'Severity scale' },
    { id: 'typography', title: 'Typography' },
    { id: 'components', title: 'Components' },
    { id: 'app-components', title: 'App components' },
    { id: 'charts', title: 'Charts' },
    { id: 'autofix', title: 'Autofix' },
];

/**
 * The neutral ladder every surface is cut from.
 *
 * Bilis spends colour on data and nowhere else, so the interface is built
 * entirely from these achromatic steps. They all carry the same faint
 * cool cast, which is what keeps them reading as one material instead of a
 * pile of unrelated greys — and what leaves severity as the only hue on
 * screen. Rendered through their utilities, so they invert with the mode.
 */
export const SURFACE_SWATCHES: Swatch[] = [
    {
        name: 'Sidebar',
        className: 'bg-sidebar text-sidebar-foreground',
        label: 'bg-sidebar',
        preview: 'Aa',
        note: 'The rail. One step below the page in both modes, so navigation separates from work without needing a colour.',
    },
    {
        name: 'Background',
        className: 'bg-background text-foreground',
        label: 'bg-background',
        preview: 'Aa',
        note: 'The page the content panel sits on.',
    },
    {
        name: 'Card',
        className: 'bg-card text-card-foreground',
        label: 'bg-card',
        preview: 'Aa',
        note: 'Every panel that should read as a surface: the toolbar, the volume strip, the log list.',
    },
    {
        name: 'Muted',
        className: 'bg-muted text-muted-foreground',
        label: 'bg-muted',
        preview: 'Aa',
        note: 'Expanded log detail and other quiet fills inside a card.',
    },
    {
        name: 'Accent',
        className: 'bg-accent text-accent-foreground',
        label: 'bg-accent',
        preview: 'Aa',
        note: 'The pointer response: row hover, menu highlight, ghost button fill.',
    },
    {
        name: 'Secondary',
        className: 'bg-secondary text-secondary-foreground',
        label: 'bg-secondary',
        preview: 'Aa',
        note: 'Selected chips and quiet buttons.',
    },
    {
        name: 'Border',
        className: 'bg-border text-foreground',
        label: 'bg-border',
        preview: 'Aa',
        note: 'Every hairline. Always 1px — emphasis comes from fill or tone, never from a heavier stroke.',
    },
    {
        name: 'Input',
        className: 'bg-input text-foreground',
        label: 'bg-input',
        preview: 'Aa',
        note: 'Field outlines, one step darker than a plain border so a transparent input still reads as an input.',
    },
    {
        name: 'Foreground',
        className: 'bg-foreground text-background',
        label: 'bg-foreground',
        preview: 'Aa',
        note: 'Ink — and the primary action, which is filled with ink rather than with an accent colour.',
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
        note: 'The page and the ink on it. Near-white on charcoal in light, charcoal on near-white in dark.',
    },
    {
        name: 'Card',
        className: 'bg-card text-card-foreground',
        label: 'bg-card text-card-foreground',
        preview: 'Aa',
        note: 'One step above the page in both modes. Toolbars, cards and the log list all sit on it.',
    },
    {
        name: 'Primary',
        className: 'bg-primary text-primary-foreground',
        label: 'bg-primary text-primary-foreground',
        preview: 'Aa',
        note: 'Ink, not a colour. The primary action is the darkest thing in light mode and the lightest in dark — this product has no accent hue.',
    },
    {
        name: 'Secondary',
        className: 'bg-secondary text-secondary-foreground',
        label: 'bg-secondary text-secondary-foreground',
        preview: 'Aa',
        note: 'Quiet buttons, and the fill of an active severity chip.',
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
        note: 'Hover and active states for rows and menus.',
    },
    {
        name: 'Destructive',
        className: 'bg-destructive text-destructive-foreground',
        label: 'bg-destructive text-destructive-foreground',
        preview: 'Aa',
        note: 'Delete team, revoke API key, error alerts. The one place a warm hue is allowed outside the severity ramp, because it warns about an action rather than describing a log.',
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
        note: 'Hairlines between log rows, cards and toolbars — clearly darker than any surface.',
    },
    {
        name: 'Input',
        className: 'border-8 border-input bg-card text-card-foreground',
        label: 'border-input',
        preview: 'Aa',
        note: 'Field outlines, a step darker than the plain border so inputs read as inputs on a card.',
    },
    {
        name: 'Ring',
        className: 'bg-card text-card-foreground ring-4 ring-ring ring-inset',
        label: 'ring-ring',
        preview: 'Aa',
        note: 'Keyboard focus. A neutral step, never a colour — focus is a shape and a weight here, not a hue.',
    },
    {
        name: 'Sidebar',
        className: 'bg-sidebar text-sidebar-foreground',
        label: 'bg-sidebar text-sidebar-foreground',
        preview: 'Aa',
        note: 'The navigation rail, one step below the page so it separates from the content it frames.',
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
        note: 'The tail gold. The first series a chart reaches for.',
    },
    {
        name: 'Chart 2',
        className: 'bg-chart-2',
        label: 'bg-chart-2',
        note: 'The tail teal. A full hue step from the first, never a lighter version of it.',
    },
    {
        name: 'Chart 3',
        className: 'bg-chart-3',
        label: 'bg-chart-3',
        note: 'The tail navy, lifted in dark mode so it holds on the dark card.',
    },
    {
        name: 'Chart 4',
        className: 'bg-chart-4',
        label: 'bg-chart-4',
        note: 'The tail crimson.',
    },
    {
        name: 'Chart 5',
        className: 'bg-chart-5',
        label: 'bg-chart-5',
        note: 'Crimson shifted toward magenta, so a five-series chart does not end on two reds.',
    },
];

/**
 * Usage notes for each severity bucket, keyed by level.
 */
export const SEVERITY_NOTES: Record<SeverityLevel, string> = {
    trace: 'Span-level noise. Achromatic on purpose — the quietest level sits below the ramp and gets no hue at all.',
    debug: 'The tail teal. Developer detail: present, but the coolest hue in the ramp.',
    info: 'The tail navy, opened up to a blue. The default, and the anchor the rest of the ramp is read against.',
    warn: 'The tail gold. Something is off but the request still succeeded.',
    error: 'The tail crimson. A request or job failed.',
    fatal: 'Crimson pushed toward magenta. The process went down — a hue away from error, not a darker shade of it, so the two loudest levels can never be confused at a glance.',
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
        projectId: '1',
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
 * Build a demo histogram for the LogsHistogram showcase: forty-eight one-minute
 * buckets shaped like a real incident — steady info traffic, a warn ramp, then
 * a burst of errors that tails off.
 */
export function demoHistogram(): LogHistogram {
    const intervalSeconds = 60;
    const start = Date.UTC(2026, 7, 26, 8, 26, 0);

    const buckets = Array.from({ length: 48 }, (_, index) => {
        const spike = index >= 30 && index <= 38;
        const ramp = index >= 26 && index < 30;

        const counts: Record<SeverityLevel, number> = {
            trace: 2 + ((index * 5) % 4),
            debug: 9 + ((index * 7) % 11),
            info: 48 + ((index * 13) % 22),
            warn: ramp ? 14 + index - 26 : spike ? 26 : 1 + ((index * 3) % 3),
            error: spike ? 38 - Math.abs(34 - index) * 5 : 0,
            fatal: index === 34 ? 3 : 0,
        };

        const total = Object.values(counts).reduce(
            (sum, value) => sum + value,
            0,
        );

        return {
            bucket: new Date(start + index * intervalSeconds * 1_000)
                .toISOString()
                .replace('T', ' ')
                .replace('Z', ''),
            counts,
            total,
        };
    });

    return {
        buckets,
        intervalSeconds,
        total: buckets.reduce((sum, entry) => sum + entry.total, 0),
        unavailable: false,
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

/**
 * A bursty 24 hour ingest curve for the sparkline demo: quiet overnight, a
 * deploy spike mid-morning, the usual afternoon plateau.
 */
export const SPARKLINE_VOLUME_24H: number[] = [
    1420, 1180, 960, 820, 760, 910, 1640, 3280, 5120, 9840, 7260, 6180, 6640,
    7120, 6980, 7340, 6820, 5940, 4680, 3520, 2840, 2260, 1880, 1540,
];

/**
 * The matching error curve — flat for most of the day, then a burst that
 * lines up with the deploy spike above.
 */
export const SPARKLINE_ERRORS_24H: number[] = [
    0, 0, 2, 0, 0, 1, 0, 4, 18, 96, 74, 31, 12, 6, 3, 2, 9, 4, 1, 0, 0, 2, 0, 0,
];

/**
 * A single service's 24 hours for the overlay demo: a steady shipper whose
 * afternoon goes wrong, plus the error share of those same hours.
 */
export const SPARKLINE_SERVICE_24H: number[] = [
    620, 580, 540, 500, 470, 520, 880, 1640, 2180, 2040, 1960, 2100, 2260, 2180,
    1940, 1880, 2240, 2380, 1720, 1280, 1040, 900, 780, 660,
];

export const SPARKLINE_SERVICE_ERRORS_24H: number[] = [
    2, 0, 1, 0, 0, 3, 4, 9, 12, 6, 8, 5, 7, 4, 6, 210, 640, 880, 410, 120, 40,
    12, 6, 3,
];

/**
 * The hour each sparkline point covers, UTC — what a DitherSparkline needs
 * before it will show a tooltip on hover.
 */
export const SPARKLINE_HOUR_LABELS: string[] = Array.from(
    { length: 24 },
    (_, hour) => `${String(hour).padStart(2, '0')}:00`,
);

/**
 * The same buckets on the other clock — what the tooltip prints beside the
 * local label. Two hours behind, so the demo shows the pair doing its job.
 */
export const SPARKLINE_UTC_HOUR_LABELS: string[] = Array.from(
    { length: 24 },
    (_, hour) => `${String((hour + 22) % 24).padStart(2, '0')}:00 UTC`,
);

/**
 * Four API keys across three projects, spanning every state the bar has: a
 * quiet shipper, a busy one, one leaning on the ceiling, and one that has
 * reached it and is being answered with 429s.
 */
export const demoIngestRate: IngestRateUsage = {
    limit: 1200,
    disabled: false,
    keys: [
        {
            project: 'checkout-api',
            projectSlug: 'checkout-api',
            name: 'Production collector',
            keyPrefix: 'bilis_9fZ1qP',
            attempts: 1200,
            remaining: 0,
        },
        {
            project: 'checkout-api',
            projectSlug: 'checkout-api',
            name: 'Staging collector',
            keyPrefix: 'bilis_2kMv8L',
            attempts: 1010,
            remaining: 190,
        },
        {
            project: 'bilis-ingest',
            projectSlug: 'bilis-ingest',
            name: 'otlp-collector',
            keyPrefix: 'bilis_7hQr4T',
            attempts: 348,
            remaining: 852,
        },
        {
            project: 'payments-gateway',
            projectSlug: 'payments-gateway',
            name: 'Nightly batch',
            keyPrefix: 'bilis_5xBn1W',
            attempts: 3,
            remaining: 1197,
        },
    ],
};

/**
 * The same keys with BILIS_INGEST_RATE_LIMIT=0: nothing is counted, so every
 * bar rests and the row reads "no limit" instead of a fraction.
 */
export const demoIngestRateDisabled: IngestRateUsage = {
    limit: 0,
    disabled: true,
    keys: demoIngestRate.keys.map((key) => ({
        ...key,
        attempts: 0,
        remaining: 0,
    })),
};

/**
 * A patch of the shape autofix actually produces: one small, defensive change
 * to the file the stack trace pointed at, against the project's own code.
 */
export const demoFixDiff = `diff --git a/app/Services/Billing/ChargeOrder.php b/app/Services/Billing/ChargeOrder.php
index 83db48f..bf269f4 100644
--- a/app/Services/Billing/ChargeOrder.php
+++ b/app/Services/Billing/ChargeOrder.php
@@ -18,9 +18,13 @@ class ChargeOrder
     public function handle(Order $order): Receipt
     {
-        $total = $order->lines->sum('amount');
+        if ($order->lines->isEmpty()) {
+            throw new EmptyOrder($order->id);
+        }
 
-        return $this->gateway->charge($total, $order->currency);
+        $total = $order->lines->sum('amount');
+
+        return $this->gateway->charge($total, $order->currency ?? 'EUR');
     }
 }
`;

/** A short excerpt of source, for the single-file rendering mode. */
export const demoFixExcerpt = `public function handle(Order $order): Receipt
{
    $total = $order->lines->sum('amount');

    return $this->gateway->charge($total, $order->currency);
}`;

/**
 * One agent session, end to end, in the schema Ayos emits: the same rows the
 * live stream appends and the persisted transcript replays.
 */
export const demoFixJobEvents: FixJobEvent[] = [
    {
        seq: 1,
        ts: '2026-08-27T09:12:01.000Z',
        type: 'phase',
        data: { state: 'cloning' },
    },
    {
        seq: 2,
        ts: '2026-08-27T09:12:04.000Z',
        type: 'phase',
        data: { state: 'cloning', done: true, duration_ms: 2333 },
    },
    {
        seq: 3,
        ts: '2026-08-27T09:12:04.000Z',
        type: 'phase',
        data: {
            state: 'queued',
            egress: ['api.anthropic.com', 'registry.npmjs.org'],
        },
    },
    {
        seq: 4,
        ts: '2026-08-27T09:12:19.000Z',
        type: 'agent_message',
        data: {
            text: 'The trace points at ChargeOrder::handle. An order with no lines sums to zero, and the gateway rejects a zero charge — that is the PaymentFailed we are seeing.',
        },
    },
    {
        seq: 5,
        ts: '2026-08-27T09:12:28.000Z',
        type: 'tool_call',
        data: {
            title: 'bash',
            input: {
                command: 'php artisan test --compact --filter=ChargeOrder',
            },
        },
    },
    {
        seq: 6,
        ts: '2026-08-27T09:12:31.000Z',
        type: 'tool_call',
        data: {
            title: 'read_file',
            input: {
                path: 'app/Services/Billing/ChargeOrder.php',
                lines: '1-40',
            },
        },
    },
    {
        seq: 7,
        ts: '2026-08-27T09:12:33.000Z',
        type: 'tool_result',
        data: { name: 'read_file', output: demoFixExcerpt },
    },
    {
        seq: 8,
        ts: '2026-08-27T09:13:02.000Z',
        type: 'test_output',
        data: {
            passed: true,
            output_tail:
                '  PASS  Tests\\Feature\\Billing\\ChargeOrderTest\n  ✓ it refuses an empty order\n\n  Tests:  14 passed (32 assertions)',
        },
    },
    {
        seq: 9,
        ts: '2026-08-27T09:13:10.000Z',
        type: 'done',
        data: { status: 'succeeded', summary: 'Guarded the empty-order case.' },
    },
];

/** The status ladder, in lifecycle order, as the jobs table renders it. */
export const demoFixJobStatuses: { status: FixJobStatus; label: string }[] = [
    { status: 'pending', label: 'Pending' },
    { status: 'dispatched', label: 'Dispatched' },
    { status: 'running', label: 'Running' },
    { status: 'validating', label: 'Validating' },
    { status: 'pr_opened', label: 'PR opened' },
    { status: 'merged', label: 'Merged' },
    { status: 'no_change', label: 'No change' },
    { status: 'rejected', label: 'Rejected' },
    { status: 'failed', label: 'Failed' },
    { status: 'timeout', label: 'Timeout' },
    { status: 'cancelled', label: 'Cancelled' },
];

/**
 * A team's model API keys, as the settings page and the new-job picker see
 * them: provider, label and the last four characters, never the key.
 */
export const demoLlmCredentials: TeamLlmCredential[] = [
    {
        id: 1,
        provider: 'anthropic',
        providerLabel: 'Anthropic',
        label: 'Production budget',
        hint: 'a91f',
        isDefault: true,
        lastUsedAt: '2026-08-27T09:14:00.000Z',
        createdAt: '2026-06-02T11:00:00.000Z',
    },
    {
        id: 2,
        provider: 'openrouter',
        providerLabel: 'OpenRouter',
        label: 'Experiments',
        hint: '4c7d',
        isDefault: false,
        lastUsedAt: null,
        createdAt: '2026-08-19T16:40:00.000Z',
    },
];

/**
 * Two repositories on one project, which is the case the service claim exists
 * for: the monolith takes everything nobody else named, and billing peeled off
 * into its own codebase.
 */
export const demoProjectRepositories: ProjectRepository[] = [
    {
        id: 1,
        repoFullName: 'acme/checkout',
        defaultBranch: 'main',
        autofixEnabled: true,
        testCmd: 'php artisan test --compact',
        maxConcurrent: 2,
        dailyBudget: 8,
        accountLogin: 'acme',
        services: ['*'],
        isCatchAll: true,
    },
    {
        id: 2,
        repoFullName: 'acme/billing',
        defaultBranch: 'trunk',
        autofixEnabled: true,
        testCmd: null,
        maxConcurrent: 1,
        dailyBudget: 5,
        accountLogin: 'acme',
        services: ['billing', 'billing-worker'],
        isCatchAll: false,
    },
];
