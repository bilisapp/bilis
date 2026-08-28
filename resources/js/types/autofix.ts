/** The lifecycle of one autofix attempt, mirroring `App\Enums\FixJobStatus`. */
export type FixJobStatus =
    | 'pending'
    | 'dispatched'
    | 'running'
    | 'validating'
    | 'pr_opened'
    | 'merged'
    | 'no_change'
    | 'rejected'
    | 'failed'
    | 'cancelled'
    | 'timeout';

/** What raised a job, mirroring `App\Enums\FixJobType`. */
export type FixJobType = 'error' | 'custom';

export type FixJobSummary = {
    uuid: string;
    type: FixJobType;
    typeLabel: string;
    /** Null on a custom job: only an error job is fingerprinted. */
    fingerprint: string | null;
    /** What names the job in a list — an exception, or the request's first words. */
    title: string;
    instructions: string | null;
    instructionsExcerpt: string | null;
    exception: string | null;
    message: string | null;
    serviceName: string | null;
    occurrences: number | null;
    status: FixJobStatus;
    statusLabel: string;
    isActive: boolean;
    project: { name: string; slug: string };
    repository: string;
    prNumber: number | null;
    prUrl: string | null;
    createdAt: string | null;
    completedAt: string | null;
};

export type FixJobDetail = FixJobSummary & {
    errorContext: Record<string, unknown> | null;
    report: Record<string, unknown> | null;
    events: FixJobEvent[];
    diff: string | null;
    baseSha: string | null;
    defaultBranch: string;
    failureReason: string | null;
    dispatchedAt: string | null;
    firstSeen: string | null;
    lastSeen: string | null;
    stack: string | null;
};

/**
 * One entry of the session transcript, in the single schema Ayos uses for the
 * live stream, its ring buffer and the persisted `events` column alike.
 */
export type FixJobEventType =
    | 'phase'
    | 'agent_message'
    | 'tool_call'
    | 'tool_result'
    | 'test_output'
    | 'error'
    | 'done';

export type FixJobEvent = {
    /** Durable events have Ayos sequence numbers; live-only deltas do not. */
    seq?: number;
    ts: string;
    type: FixJobEventType;
    durability?: 'durable' | 'ephemeral';
    data?: Record<string, unknown> | null;
};

export type FixJobPage = {
    data: FixJobSummary[];
    currentPage: number;
    lastPage: number;
    total: number;
};

export type FixJobStatusOption = { value: FixJobStatus; label: string };

export type FixJobFilters = {
    project: string | null;
    status: FixJobStatus | null;
};

/** Where the browser watches a running job, and for how long a token lasts. */
export type FixJobStream = {
    url: string;
    ttlMinutes: number;
};

export type ProjectRepository = {
    id: number;
    repoFullName: string;
    defaultBranch: string;
    autofixEnabled: boolean;
    testCmd: string | null;
    maxConcurrent: number;
    dailyBudget: number;
    accountLogin: string;
};

export type GitHubInstallationSummary = {
    id: number;
    accountLogin: string;
    accountType: string;
};

export type AvailableRepository = {
    full_name: string;
    default_branch: string;
    private: boolean;
};

export type AvailableInstallation = {
    id: number;
    accountLogin: string;
    accountType: string;
    repositories: AvailableRepository[];
};

export type ProjectAutofixConfig = {
    enabled: boolean;
    githubConfigured: boolean;
};
