export type SeverityLevel =
    'trace' | 'debug' | 'info' | 'warn' | 'error' | 'fatal';

export type LogEntry = {
    projectId: string;
    timestamp: string;
    traceId: string;
    spanId: string;
    severityText: string;
    severityNumber: number;
    serviceName: string;
    body: string;
    scopeName: string;
    scopeVersion: string;
    resourceAttributes: Record<string, string>;
    logAttributes: Record<string, string>;
};

export type LogResult = {
    rows: LogEntry[];
    nextCursor: string | null;
    unavailable: boolean;
};

export type LogProject = {
    name: string;
    slug: string;
};

export type LogFilters = {
    project: string | null;
    service: string | null;
    severity: SeverityLevel[];
    search: string | null;
    from: string;
    to: string;
    cursor: string | null;
};

export type LogRangePreset = '15m' | '1h' | '6h' | '24h' | '7d' | 'custom';

export type LogHistogramBucket = {
    /** The bucket start, as a naive UTC ClickHouse timestamp. */
    bucket: string;
    counts: Record<SeverityLevel, number>;
    total: number;
};

export type LogHistogram = {
    buckets: LogHistogramBucket[];
    /** The server-chosen bar width, used to label and to zoom into a bar. */
    intervalSeconds: number;
    total: number;
    unavailable: boolean;
};

export type LogStorageProject = {
    name: string;
    slug: string;
    rows: number;
    /** Estimated compressed bytes on disk — the table total apportioned by uncompressed share. */
    bytes: number;
};

export type LogStorageSummary = {
    /** Exact compressed bytes the logs table occupies on disk. */
    totalBytes: number;
    unavailable: boolean;
    /** Largest first. */
    projects: LogStorageProject[];
};

export type LogDigestCounts = {
    /** The last 24 hours. */
    current: number;
    /** The 24 hours before that. */
    previous: number;
    /** Whole-percent change vs the prior day, or null when there is no prior data. */
    deltaPercent: number | null;
};

export type LogDigestError = {
    /** The error body, already truncated on the server. */
    body: string;
    total: number;
};

export type LogDigestService = {
    name: string;
    /** The service's newest log line, as a naive UTC ClickHouse timestamp. */
    lastSeen: string;
    /** Nothing logged for over an hour — a dead shipper, not a healthy silence. */
    quiet: boolean;
    /**
     * The service's own 24 hourly totals, oldest first, over exactly the
     * buckets `LogDigest.series` uses — which is why the points carry no
     * timestamps of their own. A silent service is 24 zeroes: a flatline,
     * not a missing series.
     */
    series: number[];
};

/** One hour of the digest's 24 hour trend, dense: a gap is a zero, not a hole. */
export type LogDigestPoint = {
    /** The top of the hour, as a naive UTC ClickHouse timestamp. */
    bucket: string;
    total: number;
    errors: number;
};

export type LogDigest = {
    logs: LogDigestCounts;
    errors: LogDigestCounts;
    /** Up to three recurring error bodies from the last 24 hours. */
    topErrors: LogDigestError[];
    /** Quietest first. */
    services: LogDigestService[];
    /** Exactly 24 hourly points, oldest first — the tiles' sparklines. */
    series: LogDigestPoint[];
    /**
     * When the numbers were measured, as a naive UTC ClickHouse timestamp.
     *
     * Part of the cached payload, so a digest served from cache reports the
     * age of its own numbers rather than the age of the request.
     */
    generatedAt: string;
    unavailable: boolean;
};

/**
 * Which onboarding step the current team is standing on.
 *
 * Derived from real state on the server — projects the team owns, and whether
 * a single line has ever been received — never from the active filters.
 */
export type LogOnboardingStage = 'no-projects' | 'no-logs' | 'ready';

export type LogOnboarding = {
    stage: LogOnboardingStage;
};
