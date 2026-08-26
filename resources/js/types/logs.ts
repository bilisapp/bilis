export type SeverityLevel =
    'trace' | 'debug' | 'info' | 'warn' | 'error' | 'fatal';

export type LogEntry = {
    projectId: number;
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
