/**
 * The hosted Free plan, as the server measures it.
 *
 * Every allowance is soft: `used` may exceed `limit` and nothing changes for
 * the team except what the dashboard says. The frontend must never disable an
 * action because of a number in here.
 */
export type PlanAllowance = {
    used: number;
    limit: number;
};

export type PlanEvents = PlanAllowance & {
    /** Log records counted since `since`. */
    logs: number;
    /** Spans counted since `since`. */
    spans: number;
    /** Midnight UTC of the day being counted, as ClickHouse renders it. */
    since: string;
    /** ClickHouse could not answer; the meter says so rather than showing zero. */
    unavailable: boolean;
};

export type PlanUsage = {
    plan: 'free';
    projects: PlanAllowance;
    members: PlanAllowance;
    events: PlanEvents;
    retentionDays: number;
    requestsPerMinute: number;
    warnAtPercent: number;
};

/** How full one allowance is, for colour and copy. */
export type PlanAllowanceLevel = 'ok' | 'warn' | 'over';
