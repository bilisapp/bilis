import type { LogEntry } from './logs';

/**
 * How an issue is shown. `regressed` is never stored: it is a resolved issue
 * whose last event is newer than its resolution, derived on the server.
 */
export type IssueStatus = 'unresolved' | 'resolved' | 'ignored' | 'regressed';

/** What a person can set; `regressed` is not among them. */
export type IssueDecision = 'unresolved' | 'resolved' | 'ignored';

export type IssueStatusFilter = 'unresolved' | 'resolved' | 'ignored' | 'all';

export type IssueSort = 'last_seen' | 'events' | 'users' | 'first_seen';

export type IssuePeriod = '24h' | '7d' | '14d' | '30d';

export type IssueFilters = {
    period: IssuePeriod;
    project: string | null;
    service: string | null;
    environment: string | null;
    status: IssueStatusFilter;
    query: string | null;
    sort: IssueSort;
    page: number;
};

/** An issue's all-time headline, as `issue_summary` aggregates it. */
export type IssueSummary = {
    projectId: string;
    fingerprint: string;
    type: string;
    message: string;
    origin: string;
    service: string;
    services: string[];
    environments: string[];
    /** Unix milliseconds. */
    firstSeen: number;
    /** Unix milliseconds. */
    lastSeen: number;
    events: number;
    users: number;
    firstRelease: string;
    lastRelease: string;
};

/** One row of the issue list: the headline plus the window's counts. */
export type IssueListItem = IssueSummary & {
    projectSlug: string;
    projectName: string;
    windowEvents: number;
    windowUsers: number;
    /** Events per slice of the window, oldest first, for the sparkline. */
    series: number[];
    status: IssueStatus;
    /** First seen inside the selected window. */
    isNew: boolean;
};

export type IssueListResult = {
    issues: IssueListItem[];
    total: number;
    page: number;
    perPage: number;
    /** The window held more issues than one list reads. */
    truncated: boolean;
    unavailable: boolean;
    services: string[];
    environments: string[];
};

export type IssueDetail = IssueSummary & {
    projectSlug: string;
    projectName: string;
    status: IssueStatus;
    resolvedAt: string | null;
    statusChangedBy: string | null;
    statusChangedAt: string | null;
};

export type IssueEvent = {
    row: LogEntry | null;
    hasOlder: boolean;
    hasNewer: boolean;
    unavailable: boolean;
    /** The issue is listed but its events have aged out. */
    expired: boolean;
};

export type IssueException = {
    type: string;
    message: string;
    stacktrace: string;
};

export type IssueHistogramBucket = {
    /** Unix seconds. */
    bucket: number;
    events: number;
    users: number;
};

export type IssueHistogramSeries = {
    buckets: IssueHistogramBucket[];
    intervalSeconds: number;
    unavailable: boolean;
};

export type IssueHistograms = {
    day: IssueHistogramSeries;
    month: IssueHistogramSeries;
};

export type IssueTag = {
    key: string;
    total: number;
    values: { value: string; count: number }[];
};

export type IssueTagResult = {
    tags: IssueTag[];
    unavailable: boolean;
};

export type IssueEventSummary = {
    timestamp: string;
    eventId: string;
    serviceName: string;
    traceId: string;
    release: string;
    environment: string;
    user: string;
    message: string;
};

export type IssueEventList = {
    rows: IssueEventSummary[];
    unavailable: boolean;
};

/** One stack frame, from the structured attribute or parsed out of text. */
export type StackFrame = {
    filename: string;
    function: string;
    module: string;
    lineno: number | null;
    colno: number | null;
    inApp: boolean;
    preContext: string[];
    contextLine: string | null;
    postContext: string[];
    /** The frame as it was written, when it came from text. */
    raw: string;
};

/** A cause behind the thrown exception, nearest first. */
export type ExceptionCause = {
    type: string;
    message: string;
    origin: string;
    stacktrace: string;
};

export type Breadcrumb = {
    timestamp: string;
    type: string;
    category: string;
    level: string;
    message: string;
    data: string;
};
