import { RANGE_PRESETS } from '@/lib/logs';
import type { LogRangePreset, Span, SpanStatus, TraceFilters } from '@/types';

/**
 * Status colour comes from the severity ladder, not from a new hue.
 *
 * The palette has exactly two families that carry colour, and both describe
 * data: severity and chart series. A span's status is the same kind of fact a
 * log line's level is, so it borrows the same tokens rather than introducing a
 * third family — see the branding notes in DESIGN.md.
 */
export const SPAN_STATUS_TEXT_CLASS: Record<string, string> = {
    Error: 'text-severity-error',
    Ok: 'text-severity-info',
    Unset: 'text-muted-foreground',
};

export const SPAN_STATUS_BAR_CLASS: Record<string, string> = {
    Error: 'bg-severity-error',
    Ok: 'bg-severity-info',
    Unset: 'bg-muted-foreground/50',
};

/**
 * A short label for a span kind.
 *
 * `Unspecified` is the proto's zero value and means the sender said nothing, so
 * it reads as nothing rather than as a kind of its own.
 */
export const SPAN_KIND_LABEL: Record<string, string> = {
    Unspecified: '',
    Internal: 'internal',
    Server: 'server',
    Client: 'client',
    Producer: 'producer',
    Consumer: 'consumer',
};

/**
 * Render a duration in milliseconds at a readable precision.
 *
 * Sub-millisecond spans are real and common — a cache hit, a local call — so
 * they get microseconds rather than collapsing to "0 ms" and looking broken.
 */
export function formatDuration(milliseconds: number): string {
    if (!Number.isFinite(milliseconds) || milliseconds < 0) {
        return '—';
    }

    // Exactly zero is a real value on the time axis and on an instantaneous
    // span; "0 µs" is technically true and reads like a rounding artefact.
    if (milliseconds === 0) {
        return '0 ms';
    }

    if (milliseconds < 1) {
        return `${Math.round(milliseconds * 1000)} µs`;
    }

    if (milliseconds < 1000) {
        return `${milliseconds < 10 ? milliseconds.toFixed(1) : Math.round(milliseconds)} ms`;
    }

    if (milliseconds < 60_000) {
        return `${(milliseconds / 1000).toFixed(2)} s`;
    }

    const minutes = Math.floor(milliseconds / 60_000);
    const seconds = Math.round((milliseconds % 60_000) / 1000);

    return `${minutes}m ${seconds}s`;
}

/**
 * An error rate as a percentage, keeping small non-zero rates visible.
 */
export function formatErrorRate(rate: number): string {
    if (!Number.isFinite(rate) || rate <= 0) {
        return '0%';
    }

    const percent = rate * 100;

    return percent < 1 ? `${percent.toFixed(1)}%` : `${Math.round(percent)}%`;
}

/**
 * The status a span carries, narrowed to the ones the UI styles.
 */
export function spanStatus(span: Span): SpanStatus {
    return span.statusCode === 'Error' || span.statusCode === 'Ok'
        ? span.statusCode
        : 'Unset';
}

/**
 * The geometry of a waterfall bar, as percentages of the trace's own span.
 *
 * Offsets are measured against the earliest span present rather than against
 * the summary's Start: when a trace is truncated or its root has aged out, the
 * bars must still line up with each other, and a bar starting at -4% would be
 * drawn off the left edge.
 */
export function waterfallGeometry(spans: Span[]): {
    offsetPercent: number;
    widthPercent: number;
}[] {
    if (spans.length === 0) {
        return [];
    }

    const starts = spans.map((span) => Date.parse(`${span.timestamp}Z`));
    const earliest = Math.min(...starts);
    const latest = Math.max(
        ...spans.map((span, index) => starts[index] + span.durationMs),
    );

    // A trace whose spans all share one instant still has to draw something.
    const total = Math.max(latest - earliest, 1);

    return spans.map((span, index) => {
        const offset = ((starts[index] - earliest) / total) * 100;
        const width = (span.durationMs / total) * 100;

        return {
            offsetPercent: Math.max(0, Math.min(100, offset)),
            // A floor of 0.4% so an instantaneous span is still a visible mark
            // rather than a bar of zero width.
            widthPercent: Math.max(0.4, Math.min(100 - offset, width)),
        };
    });
}

/**
 * The chart series palette, as Tailwind background utilities.
 *
 * A waterfall bar is coloured by service, and a service is a data series — so it
 * spends the sanctioned `--chart-*` palette rather than introducing a colour
 * family of its own. Five slots, cycled: a trace touching more than five
 * services reuses a colour, which is a legible collision because the legend is
 * right there and the service name is on the row.
 */
export const SERVICE_BAR_CLASS = [
    'bg-chart-1',
    'bg-chart-2',
    'bg-chart-3',
    'bg-chart-4',
    'bg-chart-5',
] as const;

/**
 * Assign each service in a trace a stable palette slot.
 *
 * Ordered by first appearance rather than alphabetically, so the root service
 * always takes the first slot and the eye learns the trace's own hierarchy
 * rather than an ordering it did not ask for.
 */
export function serviceColours(spans: Span[]): Map<string, string> {
    const assigned = new Map<string, string>();

    for (const span of spans) {
        const service = span.serviceName || 'unknown';

        if (!assigned.has(service)) {
            assigned.set(
                service,
                SERVICE_BAR_CLASS[assigned.size % SERVICE_BAR_CLASS.length],
            );
        }
    }

    return assigned;
}

/**
 * Read the first of several attribute keys that carries a value.
 *
 * An attribute present but empty is the same as absent for display: the sender
 * said the key exists, not that it means anything.
 */
function attribute(
    attributes: Record<string, string>,
    ...keys: string[]
): string | undefined {
    for (const key of keys) {
        const value = attributes[key];

        if (value !== undefined && value !== '') {
            return value;
        }
    }

    return undefined;
}

/**
 * Collapse a value onto one line, short enough for a table cell.
 *
 * Attribute values are not written for a column — a shell command carries
 * newlines and a heredoc, a SQL statement is indented. Left raw they break the
 * row height and push everything else off screen.
 */
function condense(value: string, max = 90): string {
    const flat = value.replace(/\s+/g, ' ').trim();

    return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat;
}

/**
 * A token count as a number, or undefined when the attribute is absent or junk.
 */
function tokenCount(value: string | undefined): number | undefined {
    const count = Number(value);

    return Number.isFinite(count) ? count : undefined;
}

/**
 * Everything the model actually read, not just the part nobody had cached.
 *
 * `input_tokens` counts *uncached* input alone — with prompt caching on, a
 * request that read 121k tokens of context reports 2, and a column of
 * `2→104 tok` says nothing about a session where the number is 2 every single
 * time. The tokens the model saw are the uncached remainder plus the cache
 * read plus whatever this call wrote into the cache, so that is what gets
 * shown; the split stays one click away in the attributes.
 */
function totalInputTokens(
    attributes: Record<string, string>,
): number | undefined {
    const input = tokenCount(
        attribute(attributes, 'gen_ai.usage.input_tokens', 'input_tokens'),
    );

    if (input === undefined) {
        return undefined;
    }

    const cacheRead = tokenCount(
        attribute(
            attributes,
            'gen_ai.usage.cache_read_tokens',
            'cache_read_tokens',
        ),
    );
    const cacheWrite = tokenCount(
        attribute(
            attributes,
            'gen_ai.usage.cache_creation_tokens',
            'cache_creation_tokens',
        ),
    );

    return input + (cacheRead ?? 0) + (cacheWrite ?? 0);
}

/**
 * A token count at a width that fits beside another one.
 */
function compactCount(count: number): string {
    return count >= 10_000
        ? `${(count / 1000).toFixed(1)}k`
        : String(Math.round(count));
}

/**
 * A model id at the width of a column, not of a config file.
 *
 * The vendor prefix repeats on every row and the training date never
 * distinguishes two spans in the same trace, so both come off:
 * `claude-haiku-4-5-20251001` reads as `haiku-4.5`.
 */
function shortModelName(model: string): string {
    return model
        .replace(/^(claude|gpt|gemini|models)[-/]/, '')
        .replace(/-\d{8}$/, '')
        .replace(/(\d)-(\d)/g, '$1.$2');
}

/**
 * How a span is named in a list.
 *
 * `smart` derives a label from the span's attributes; `raw` shows exactly what
 * the exporter sent. The derived label is a reading aid and the reader must be
 * able to get behind it in one click — a label Bilis composed and a name the
 * sender chose are different claims, and a viewer that quietly conflates them
 * is lying about the data. Smart is the default because the raw view is the one
 * that sent us here.
 */
export type SpanNaming = 'smart' | 'raw';

/**
 * What a span *is*, for the Detail column: the kind of work, not which one.
 *
 * Deliberately low-cardinality. A column earns its width by being scannable —
 * `Bash` repeated two hundred times next to eleven `Read`s tells you where a
 * session went, where two hundred distinct shell commands would not. Derived
 * from OpenTelemetry's own semantic conventions rather than invented, and
 * falling back to the span kind, which is all a bare span has.
 */
export function spanDetail(span: Span, naming: SpanNaming = 'smart'): string {
    const attributes = span.attributes;

    if (naming === 'raw') {
        return SPAN_KIND_LABEL[span.kind] ?? '';
    }

    const system = attribute(
        attributes,
        'db.system.name',
        'db.system',
        'messaging.system',
        'rpc.system',
        'faas.trigger',
    );

    if (system) {
        return system;
    }

    const tool = attribute(attributes, 'gen_ai.tool.name', 'tool_name');

    if (tool) {
        return tool;
    }

    const model = attribute(
        attributes,
        'gen_ai.request.model',
        'gen_ai.response.model',
        'model',
    );

    if (model) {
        return shortModelName(model);
    }

    if (attribute(attributes, 'decision')) {
        return 'approval';
    }

    if (attribute(attributes, 'http.request.method', 'http.method')) {
        return 'HTTP';
    }

    return SPAN_KIND_LABEL[span.kind] ?? '';
}

/**
 * The rules that turn a span's attributes into a label, first match wins.
 *
 * A span *name* is meant to be low-cardinality — OpenTelemetry says so — which
 * means for a great many instrumentations it names a type and not an instance.
 * Four hundred rows reading `claude_code.tool` are all correctly named and
 * collectively say nothing. The identity is in the attributes; this is where it
 * comes back out.
 *
 * Every rule keys on a published semantic-convention attribute (with the older
 * spelling accepted alongside the current one), so a span earns a label by
 * being well-described rather than by coming from a vendor Bilis has heard of.
 */
const SPAN_LABEL_RULES: ((
    attributes: Record<string, string>,
) => string | undefined)[] = [
    // The route, never the URL: a raw path puts an id in every label and the
    // column stops being scannable — the exact failure this is fixing.
    (a) => {
        const route = attribute(a, 'http.route', 'url.template');
        const method = attribute(a, 'http.request.method', 'http.method');

        return route ? [method, route].filter(Boolean).join(' ') : undefined;
    },

    (a) => {
        const statement = attribute(a, 'db.query.text', 'db.statement');

        return statement ? condense(statement) : undefined;
    },

    (a) => {
        const operation = attribute(a, 'db.operation.name', 'db.operation');
        const target = attribute(
            a,
            'db.collection.name',
            'db.sql.table',
            'db.namespace',
        );

        return operation || target
            ? [operation, target].filter(Boolean).join(' ')
            : undefined;
    },

    (a) => {
        const operation = attribute(
            a,
            'messaging.operation.name',
            'messaging.operation',
        );
        const destination = attribute(
            a,
            'messaging.destination.name',
            'messaging.destination',
        );

        return destination
            ? [operation, destination].filter(Boolean).join(' ')
            : undefined;
    },

    (a) => {
        const service = attribute(a, 'rpc.service');
        const method = attribute(a, 'rpc.method');

        return service && method ? `${service}/${method}` : undefined;
    },

    // An agent's tool call. The argument is the identity — which command, which
    // file, which subagent — so it takes the column, and the tool name goes to
    // Detail where its low cardinality is the point.
    (a) => {
        const argument = attribute(
            a,
            'full_command',
            'file_path',
            'subagent_type',
            'url.full',
            'gen_ai.tool.call.arguments',
        );

        if (argument) {
            return condense(argument);
        }

        return attribute(a, 'gen_ai.tool.name', 'tool_name');
    },

    // A model call, distinguished by what it cost rather than by the model —
    // which is already in Detail, and is the same for most of a session.
    (a) => {
        const total = totalInputTokens(a);
        const output = tokenCount(
            attribute(a, 'gen_ai.usage.output_tokens', 'output_tokens'),
        );

        if (total === undefined || output === undefined) {
            return undefined;
        }

        const parts = [`${compactCount(total)}→${compactCount(output)} tok`];
        const cached = tokenCount(
            attribute(a, 'gen_ai.usage.cache_read_tokens', 'cache_read_tokens'),
        );

        if (cached && total > 0) {
            parts.push(`${Math.round((cached / total) * 100)}% cached`);
        }

        return parts.join(' · ');
    },

    // A human in the loop. `source` says whether a person actually looked.
    (a) => {
        const decision = attribute(a, 'decision');
        const source = attribute(a, 'source');

        return decision
            ? [decision, source].filter(Boolean).join(' · ')
            : undefined;
    },

    (a) => {
        const prompt = attribute(a, 'user_prompt', 'gen_ai.prompt');

        return prompt ? condense(prompt) : undefined;
    },
];

/**
 * What to call a span in a list, given what it actually carries.
 *
 * Falls back to the span's own name, which is right for instrumentation that
 * already names instances (`GET /orders/:id`) and is the only honest answer for
 * a span carrying nothing else.
 */
export function spanLabel(span: Span, naming: SpanNaming = 'smart'): string {
    if (naming === 'raw') {
        return span.name;
    }

    for (const rule of SPAN_LABEL_RULES) {
        const label = rule(span.attributes);

        if (label) {
            return label;
        }
    }

    return span.name;
}

/** One labelled gridline on the waterfall's time axis. */
export type AxisTick = { label: string; percent: number };

/**
 * Gridlines for the timeline header, on a 1/2/5 × 10ⁿ ladder.
 *
 * The axis is what turns a row of bars into a chart you can read a number off,
 * so the ticks have to land on values a person would choose — 250ms, not 237ms.
 */
export function axisTicks(totalMs: number, target = 6): AxisTick[] {
    if (!Number.isFinite(totalMs) || totalMs <= 0) {
        return [];
    }

    const rough = totalMs / target;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const normalised = rough / magnitude;

    const step =
        (normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10) *
        magnitude;

    const ticks: AxisTick[] = [];

    for (let value = 0; value <= totalMs; value += step) {
        ticks.push({
            label: formatDuration(value),
            percent: (value / totalMs) * 100,
        });
    }

    return ticks;
}

/**
 * The trace's own extent in milliseconds, measured from the spans present.
 *
 * Deliberately not taken from the summary: a truncated trace, or one whose root
 * has aged out, must still draw an axis that matches the bars underneath it.
 */
export function traceExtentMs(spans: Span[]): number {
    if (spans.length === 0) {
        return 0;
    }

    const starts = spans.map((span) => Date.parse(`${span.timestamp}Z`));
    const earliest = Math.min(...starts);
    const latest = Math.max(
        ...spans.map((span, index) => starts[index] + span.durationMs),
    );

    return Math.max(latest - earliest, 1);
}

/**
 * Hide the descendants of every collapsed span, over the flat list.
 *
 * One pass, no tree: rows arrive depth-first, so everything deeper than a
 * collapsed row belongs to it until the depth comes back up.
 */
export function visibleSpans(spans: Span[], collapsed: Set<string>): Span[] {
    const visible: Span[] = [];
    let hiddenBelow: number | null = null;

    for (const span of spans) {
        if (hiddenBelow !== null) {
            if (span.depth > hiddenBelow) {
                continue;
            }

            hiddenBelow = null;
        }

        visible.push(span);

        if (collapsed.has(span.spanId) && span.childCount > 0) {
            hiddenBelow = span.depth;
        }
    }

    return visible;
}

/**
 * Every span that has children, for expand-all / collapse-all.
 */
export function collapsibleSpanIds(spans: Span[]): string[] {
    return spans
        .filter((span) => span.childCount > 0)
        .map((span) => span.spanId);
}

/**
 * The window a trace query should run over, given the toolbar's preset.
 *
 * A preset is relative — "last hour" means the hour ending now, not the hour
 * that ended when the page was opened — so it is resolved against the clock
 * every time a link is built. Only a custom range keeps the absolute bounds
 * the filters arrived with.
 */
export function traceWindow(
    filters: TraceFilters,
    range: LogRangePreset,
): { from: string; to: string } {
    const minutes = RANGE_PRESETS.find(
        (preset) => preset.value === range,
    )?.minutes;

    if (minutes === undefined) {
        return { from: filters.from, to: filters.to };
    }

    const to = new Date();

    return {
        from: new Date(to.getTime() - minutes * 60_000).toISOString(),
        to: to.toISOString(),
    };
}

/**
 * The query string a trace view is described by.
 *
 * The URL is the state: a filtered view is a link someone can send, the back
 * button walks the filters, and — since traces split across two tabs — the tab
 * links carry it too, so moving between the list and the latency chart never
 * silently changes the window you are reading. Built in one place for exactly
 * that reason: two builders would drift and the drift would look like a bug in
 * the data.
 */
export function traceFilterQuery(
    filters: TraceFilters,
    range: LogRangePreset,
    changes: Record<string, string | number | boolean | null> = {},
): Record<string, string> {
    const merged: Record<string, string | number | boolean | null> = {
        project: filters.project,
        service: filters.service,
        errors: filters.errors,
        min_duration: filters.minDuration,
        ...traceWindow(filters, range),
        ...changes,
    };

    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(merged)) {
        if (value === null || value === '' || value === false) {
            continue;
        }

        query[key] = value === true ? '1' : String(value);
    }

    return query;
}
