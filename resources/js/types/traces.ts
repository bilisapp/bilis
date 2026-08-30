/**
 * The literals the exporter writes into `SpanKind`, and Bilis with it.
 *
 * pdata's `SpanKind.String()`, not the proto's enum names — see SCHEMA.md R10.
 */
export type SpanKind =
    'Unspecified' | 'Internal' | 'Server' | 'Client' | 'Producer' | 'Consumer';

/** pdata's `StatusCode.String()`. `Error` is what the summary view counts. */
export type SpanStatus = 'Unset' | 'Ok' | 'Error';

export type SpanEvent = {
    /** A naive UTC ClickHouse timestamp. */
    timestamp: string;
    name: string;
    attributes: Record<string, string>;
};

/**
 * One span link: another span, usually in another trace, that this span says it
 * belongs with.
 *
 * `attributes` is where the exporter says what the relationship means — Claude
 * Code writes `link.type: parent_of` on every `claude_code.llm_request` — and
 * the target trace may or may not be stored here, which is why the page asks
 * before offering to open it.
 */
export type SpanLink = {
    traceId: string;
    spanId: string;
    traceState: string;
    attributes: Record<string, string>;
};

/**
 * A trace this instance actually holds, keyed by id in the `linkedTraces` prop.
 *
 * A `TraceSummary` rather than a bare "yes": the link needs the trace's start
 * time to keep the span query it opens bounded, and showing the root operation
 * beats showing 32 hex characters.
 */
export type LinkedTrace = TraceSummary;

export type Span = {
    /** The span's start, as a naive UTC ClickHouse timestamp. */
    timestamp: string;
    traceId: string;
    spanId: string;
    /**
     * Empty when the span is a root. It may also name a span that is not in
     * this result set — an aged-out parent, one still in flight, or one past
     * the row cap — in which case the tree renders this span at the top level
     * rather than dropping it.
     */
    parentSpanId: string;
    name: string;
    kind: SpanKind | string;
    serviceName: string;
    durationMs: number;
    statusCode: SpanStatus | string;
    statusMessage: string;
    attributes: Record<string, string>;
    events: SpanEvent[];
    /**
     * Spans in other traces this one points at. Position-aligned parallel
     * arrays in ClickHouse (R12), rebuilt into objects by `TraceQuery`.
     */
    links: SpanLink[];
    /** Nesting level, assigned server-side by `SpanTree::flatten()`. */
    depth: number;
    /** Direct children, for the disclosure triangle and the count badge. */
    childCount: number;
};

/**
 * The resource a trace was produced by, read once from its root span.
 *
 * Resource attributes are identical across a service's spans, so they are
 * fetched separately rather than repeated on every row of the waterfall.
 */
export type TraceResource = {
    attributes: Record<string, string>;
    scopeName: string;
    scopeVersion: string;
};

export type TraceSummary = {
    traceId: string;
    /** The root span's operation. Empty when the root has not arrived. */
    rootName: string;
    rootService: string;
    /** Naive UTC ClickHouse timestamps. */
    startedAt: string;
    endedAt: string;
    durationMs: number;
    spanCount: number;
    errorCount: number;
};

export type TraceResult = {
    rows: TraceSummary[];
    nextCursor: string | null;
    unavailable: boolean;
};

export type TraceFilters = {
    project: string | null;
    /** Matches the trace's ROOT service, which is all the summary table knows. */
    service: string | null;
    errors: boolean;
    /** Milliseconds. */
    minDuration: number | null;
    from: string;
    to: string;
    cursor: string | null;
};

export type ServiceLatency = {
    serviceName: string;
    spans: number;
    p95Ms: number;
    p99Ms: number;
    errors: number;
    /** A fraction, not a percentage. */
    errorRate: number;
};

export type ServiceLatencyResult = {
    rows: ServiceLatency[];
    unavailable: boolean;
};

/**
 * What the log viewer's trace preview panel receives.
 *
 * A deliberately smaller payload than the trace page's: no resource map and no
 * span limit, because the panel is a peek and "Go to detail" is one click away
 * for anything it does not carry.
 */
export type TracePanelResult = {
    traceId: string;
    /** Null when nothing at all is stored for this id. */
    summary: TraceSummary | null;
    /** Already flattened depth-first by SpanTree, orphans at root level. */
    spans: Span[];
    truncated: boolean;
    unavailable: boolean;
};
