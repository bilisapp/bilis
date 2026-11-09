/**
 * Alerting, as the Alerts page and the rule editor receive it
 * (`AlertsController`, `NotificationChannel::toSummary()`).
 */

export type AlertSourceKey = 'logs' | 'traces' | 'metrics';

export type AlertCondition = '>' | '>=' | '<' | '<=';

export type AlertSeverity = 'info' | 'warning' | 'critical';

export type AlertInstanceState = 'inactive' | 'pending' | 'firing';

export type AlertRuleHealth = 'unknown' | 'ok' | 'err';

export type NoDataState = 'inactive' | 'firing' | 'keep_last';

export type AlertEventType =
    'firing' | 'resolved' | 'issue_new' | 'issue_regressed' | 'rule_error';

export type NotificationChannelType = 'email' | 'slack' | 'webhook';

/** Labels of one series: `{}` when the rule is not grouped. */
export type AlertLabels = Record<string, string>;

/**
 * A rule's structured query. Every source has `aggregation`; the rest is the
 * source's own (`LogAlertSource`, `TraceAlertSource`, `MetricAlertSource`).
 */
export type AlertQuery = {
    aggregation: string;
    group_by?: string | null;
    /** Metrics only. */
    metric?: string;
    /**
     * Logs: `{services?, severities?, search?}`; traces: `{services?,
     * span_name?, kind?}`; metrics: attribute => value.
     */
    filters?: Record<string, string | string[] | null>;
    /**
     * Logs (count, rate) and traces: divide the reading by the same reading
     * this many seconds earlier (86400 or 604800), floored at 1 — "3× normal".
     */
    compare?: AlertCompare | null;
    /** Logs and traces: fewer events than this in the window is no data. */
    min_events?: number | null;
};

export type AlertCompare = { offset_seconds: number };

/** The parts of a rule needed to say what it watches, in one line. */
export type AlertRuleShape = {
    source: AlertSourceKey | string;
    query: AlertQuery;
    condition: AlertCondition;
    threshold: number;
    windowSeconds?: number;
    forSeconds?: number;
};

export type AlertChannelRef = {
    id: number;
    name: string;
    type: NotificationChannelType;
    enabled: boolean;
};

export type AlertRuleSummary = {
    id: number;
    name: string;
    source: AlertSourceKey;
    query: AlertQuery;
    condition: AlertCondition;
    threshold: number;
    windowSeconds: number;
    intervalSeconds: number;
    forSeconds: number;
    severity: AlertSeverity;
    enabled: boolean;
    /** ISO 8601, only while the mute is in force. */
    mutedUntil: string | null;
    health: AlertRuleHealth;
    lastError: string | null;
    lastEvaluatedAt: string | null;
    firing: number;
    pending: number;
    channels: AlertChannelRef[];
} & AlertTemplateFacts;

/**
 * Whether Bilis set a rule up from its catalogue (`config/alerting_templates.php`),
 * the catalogue's one-line reason, and whether a person has edited it since —
 * after which provisioning leaves it alone.
 */
export type AlertTemplateFacts = {
    template: boolean;
    why: string | null;
    customized: boolean;
};

/**
 * One entry of the template catalogue (`config/alerting_templates.php`) on
 * the Alerts page's Templates tab, and where it stands for this team: `on`,
 * turned `off`, or `available` — not set up yet, because its data has not
 * been seen or it was never turned on.
 */
export type AlertTemplateSummary = {
    key: string;
    name: string;
    why: string;
    requires: 'logs' | 'traces' | 'hosts';
    source: AlertSourceKey;
    query: AlertQuery;
    condition: AlertCondition;
    threshold: number;
    windowSeconds: number;
    intervalSeconds: number;
    forSeconds: number;
    severity: AlertSeverity;
    status: 'on' | 'off' | 'available';
    /** The team's rule from this template, once there is one. */
    ruleId: number | null;
    customized: boolean;
};

export type AlertInstanceSummary = {
    id: number;
    ruleId: number;
    ruleName: string;
    source: AlertSourceKey;
    query: AlertQuery;
    condition: AlertCondition;
    threshold: number;
    severity: AlertSeverity;
    muted: boolean;
    labels: AlertLabels;
    state: AlertInstanceState;
    value: number | null;
    activeAt: string | null;
    firedAt: string | null;
};

export type AlertEventSummary = {
    id: number;
    type: AlertEventType;
    /** Null once the rule is deleted, and for issue events. */
    ruleId: number | null;
    ruleName: string | null;
    source: AlertSourceKey | null;
    query: AlertQuery | null;
    condition: AlertCondition | null;
    labels: AlertLabels;
    value: number | null;
    threshold: number | null;
    occurredAt: string;
    headline: string;
    /** The explorer over the evaluated window, as the notification linked it. */
    href: string | null;
};

/** The editor's fields, exactly as they are posted. */
export type AlertRuleForm = {
    name: string;
    /** Null is every project of the team, including ones created later. */
    project_ids: number[] | null;
    source: AlertSourceKey;
    query: AlertQuery;
    condition: AlertCondition;
    threshold: number;
    window_seconds: number;
    interval_seconds: number;
    for_seconds: number;
    keep_firing_for_seconds: number;
    no_data_state: NoDataState;
    severity: AlertSeverity;
    channel_ids: number[];
};

export type AlertRuleEditing = AlertRuleForm & {
    id: number;
    enabled: boolean;
    mutedUntil: string | null;
    health: AlertRuleHealth;
    lastError: string | null;
    lastEvaluatedAt: string | null;
    instances: AlertInstanceSummary[];
} & AlertTemplateFacts;

export type AlertEditorOptions = {
    sources: AlertSourceKey[];
    logs: { aggregations: string[]; groupBy: string[]; severities: string[] };
    traces: {
        aggregations: string[];
        groupBy: string[];
        kinds: string[];
        maxWindowSeconds: number;
    };
    metrics: { aggregations: string[]; maxFilters: number };
    conditions: AlertCondition[];
    intervals: number[];
    noDataStates: NoDataState[];
    severities: AlertSeverity[];
    maxWindowSeconds: number;
    maxHoldSeconds: number;
    previewHours: number;
};

export type AlertMetricCatalog = {
    metrics: { name: string; type: string; unit: string }[];
    unavailable: boolean;
};

/** One preview line: unix seconds => value (null is a gap). */
export type AlertPreviewSeries = {
    labels: AlertLabels;
    points: [number, number | null][];
};

export type AlertPreviewResult = {
    series: AlertPreviewSeries[];
    unavailable: boolean;
    /** Why the source refused, when that is worth reading; null when busy. */
    reason: string | null;
    stepSeconds: number;
    windowSeconds: number;
    /** Counts were rescaled from the bucket width to the rule's window. */
    scaled: boolean;
};

export type NotificationChannelSummary = {
    id: number;
    type: NotificationChannelType;
    name: string;
    enabled: boolean;
    issueEvents: boolean;
    /** What it delivers to, masked: a host, never a URL. */
    target: string;
    recipients: 'members' | null;
    addresses: string[];
    host: string | null;
    hasSecret: boolean;
    lastFailedAt: string | null;
    lastError: string | null;
};

/** Flashed once, after a webhook channel is created with a generated secret. */
export type NewChannelSecret = {
    channelId: number;
    name: string;
    secret: string;
};

export type AlertBadgeCounts = {
    firing: number;
};

/** One row of the dashboard's "Needs attention" card (`NeedsAttention`). */
export type AlertAttentionItem = AlertInstanceSummary & {
    /** The explorer from one window before the series turned active until now. */
    explorerHref: string | null;
    ruleHref: string;
};

/** Deferred on the dashboard: at most eight items, `total` counts them all. */
export type NeedsAttentionList = {
    items: AlertAttentionItem[];
    /** Every firing and pending series, not only the items shown. */
    total: number;
    /** Of `total`, how many are firing. */
    firing: number;
};
