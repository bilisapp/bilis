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
