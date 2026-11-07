import { show as issueShow } from '@/routes/issues';
import type {
    Breadcrumb,
    ExceptionCause,
    IssueDecision,
    IssueFilters,
    IssuePeriod,
    IssueSort,
    IssueStatus,
    IssueStatusFilter,
    LogEntry,
    StackFrame,
} from '@/types';

export const ISSUE_STATUS_LABEL: Record<IssueStatus, string> = {
    unresolved: 'Unresolved',
    resolved: 'Resolved',
    ignored: 'Ignored',
    regressed: 'Regressed',
};

export const ISSUE_STATUS_FILTERS: {
    value: IssueStatusFilter;
    label: string;
}[] = [
    { value: 'unresolved', label: 'Unresolved' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'ignored', label: 'Ignored' },
    { value: 'all', label: 'All' },
];

export const ISSUE_SORTS: { value: IssueSort; label: string }[] = [
    { value: 'last_seen', label: 'Last seen' },
    { value: 'events', label: 'Events' },
    { value: 'users', label: 'Users' },
    { value: 'first_seen', label: 'First seen' },
];

export const ISSUE_PERIOD_LABEL: Record<IssuePeriod, string> = {
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '14d': 'Last 14 days',
    '30d': 'Last 30 days',
};

/**
 * The action a status offers next: resolve or ignore something open, reopen
 * something closed. A regressed issue is open again by definition.
 */
export function isOpen(status: IssueStatus): boolean {
    return status === 'unresolved' || status === 'regressed';
}

/**
 * The one builder for links to an issue page.
 *
 * `event` is a ClickHouse timestamp, `eventKey` tells it from other events
 * on the same timestamp, and `position` says what to do with them — so
 * stepping through events is a link like any other.
 */
export function issueHref(
    teamSlug: string,
    projectSlug: string,
    fingerprint: string,
    options: {
        position?: 'latest' | 'oldest' | 'older' | 'newer' | 'at';
        event?: string | null;
        /** The event's tie-breaker within its timestamp. */
        eventKey?: string | null;
    } = {},
): string {
    const query: Record<string, string> = {};

    if (options.position && options.position !== 'latest') {
        query.position = options.position;
    }

    if (options.event) {
        query.event = options.event;

        if (options.eventKey) {
            query.key = options.eventKey;
        }
    }

    return issueShow(
        { current_team: teamSlug, project: projectSlug, fingerprint },
        { query },
    ).url;
}

/**
 * A count as people read it at a glance: 950, 1.2k, 34k, 1.5M.
 */
export function formatCount(count: number): string {
    if (count < 1_000) {
        return String(count);
    }

    const [divisor, suffix] =
        count < 1_000_000 ? [1_000, 'k'] : [1_000_000, 'M'];
    const value = count / divisor;

    return `${value < 10 ? Math.floor(value * 10) / 10 : Math.floor(value)}${suffix}`;
}

/** Unix milliseconds as the ISO string the shared time helpers read. */
export function isoFromMs(milliseconds: number): string {
    return new Date(milliseconds).toISOString();
}

/** What a decision is called on the button that makes it. */
export const DECISION_LABEL: Record<IssueDecision, string> = {
    resolved: 'Resolve',
    ignored: 'Ignore',
    unresolved: 'Reopen',
};

/*
|--------------------------------------------------------------------------
| Stack frames
|--------------------------------------------------------------------------
|
| An event from an error-reporting SDK carries its frames as structured JSON
| (`exception.frames`); everything else carries a stack as text, in whichever
| dialect its runtime prints. Both become the same StackFrame, most recent
| call first, so the page renders one shape.
*/

/** Path fragments that mark a frame as somebody else's code. */
const VENDOR_PATH =
    /(^|[\\/])(vendor|node_modules|site-packages|dist-packages|gems|\.cargo|pkg[\\/]mod|bower_components|Pods)[\\/]/;

/** Runtime-internal locations: no file, or the runtime's own. */
const INTERNAL_FILE = /^(node:|internal[\\/]|\[internal|<anonymous>|native$)/;

/** Packages that belong to the JVM rather than the application. */
const JVM_PACKAGE = /^(java|javax|jdk|sun|com\.sun|kotlin|kotlinx|scala)\./;

/**
 * Whether a frame is the application's own code.
 *
 * A heuristic, used only when the sender did not say: an SDK that sends
 * `in_app` is believed over it.
 */
export function isInAppFrame(filename: string, fn = '', module = ''): boolean {
    if (filename === '' && fn === '') {
        return false;
    }

    if (fn === '{main}' || INTERNAL_FILE.test(filename)) {
        return false;
    }

    if (VENDOR_PATH.test(filename)) {
        return false;
    }

    if (JVM_PACKAGE.test(module) || JVM_PACKAGE.test(fn)) {
        return false;
    }

    return true;
}

function frame(partial: Partial<StackFrame> & { raw: string }): StackFrame {
    const filename = partial.filename ?? '';
    const fn = partial.function ?? '';
    const module = partial.module ?? '';

    return {
        filename,
        function: fn,
        module,
        lineno: partial.lineno ?? null,
        colno: partial.colno ?? null,
        inApp: partial.inApp ?? isInAppFrame(filename, fn, module),
        preContext: partial.preContext ?? [],
        contextLine: partial.contextLine ?? null,
        postContext: partial.postContext ?? [],
        raw: partial.raw,
    };
}

function lineNumber(value: string | undefined): number | null {
    if (value === undefined || value === '') {
        return null;
    }

    const parsed = Number.parseInt(value, 10);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * A cause marker that comes AFTER the thrown exception's frames (Java, and
 * Bilis's own PHP shipper): the thrown exception is printed first.
 */
const CAUSE_AFTER = /^\s*Caused by:/;

/**
 * Markers of the other convention, where the innermost cause is printed first
 * and the thrown exception last: PHP's `(string) $exception` (`Next …`) and
 * Python's chained tracebacks. The Python sentences only separate sections;
 * `Next …` is the header of the section it starts.
 */
const CAUSE_BEFORE =
    /^(Next |The above exception was the direct cause|During handling of the above exception)/;

const PYTHON_SEPARATOR =
    /^(The above exception was the direct cause|During handling of the above exception)/;

/**
 * Split a printed chain into the thrown exception and its causes.
 *
 * `causes` is nearest first whichever way round the runtime printed them.
 */
export function splitStackSections(text: string): {
    thrown: string;
    causes: string[];
} {
    const sections: string[][] = [[]];
    let causeFirst = false;

    for (const line of text.split(/\r\n|\r|\n/)) {
        if (CAUSE_BEFORE.test(line)) {
            causeFirst = true;
            sections.push(PYTHON_SEPARATOR.test(line) ? [] : [line]);
            continue;
        }

        if (CAUSE_AFTER.test(line)) {
            sections.push([line]);
            continue;
        }

        sections[sections.length - 1].push(line);
    }

    const texts = sections
        .map((section) => section.join('\n').trim())
        .filter((section) => section !== '');

    if (texts.length === 0) {
        return { thrown: '', causes: [] };
    }

    return causeFirst
        ? {
              thrown: texts[texts.length - 1],
              causes: texts.slice(0, -1).reverse(),
          }
        : { thrown: texts[0], causes: texts.slice(1) };
}

/**
 * Parse a printed stack trace into frames, most recent call first.
 *
 * Understands the dialects Bilis actually receives: PHP (`#0 file(12): fn`),
 * the envelope mapper's own (`#0 file:12 fn()`), V8 (`at fn (file:12:3)`),
 * Java (`at a.b.C.m(C.java:12)`), Python (`File "x", line 12, in fn`, oldest
 * first, so reversed), Ruby (`file:12:in 'fn'`) and Go (function line, then a
 * tab-indented `file:line`). Only the thrown exception's frames are returned —
 * in a chain printed cause-first (PHP `Next`, Python) that is the LAST
 * section; causes are {@link parseCausesFromText}'s. Lines it does not
 * recognise are skipped.
 */
export function parseStackTrace(text: string): StackFrame[] {
    return parseFrames(splitStackSections(text).thrown);
}

function parseFrames(text: string): StackFrame[] {
    const lines = text.split(/\r\n|\r|\n/);
    const frames: StackFrame[] = [];
    let pythonOrder = false;

    for (let index = 0; index < lines.length; index++) {
        const line = lines[index];

        if (/^Traceback \(most recent call last\)/.test(line.trim())) {
            pythonOrder = true;
            continue;
        }

        const trimmed = line.trim();

        // PHP: #0 /srv/app/Ledger.php(12): App\Ledger->lock()
        let match = /^#\d+\s+(.+?)\((\d+)\):\s*(.*)$/.exec(trimmed);

        if (match) {
            frames.push(
                frame({
                    filename: match[1],
                    lineno: lineNumber(match[2]),
                    function: match[3],
                    raw: trimmed,
                }),
            );
            continue;
        }

        // The envelope mapper's text form: #0 app/Payments/Charge.php:41 charge()
        match = /^#\d+\s+(\S+?):(\d+)(?::(\d+))?(?:\s+(.+?))?(?:\(\))?$/.exec(
            trimmed,
        );

        if (match) {
            frames.push(
                frame({
                    filename: match[1],
                    lineno: lineNumber(match[2]),
                    colno: lineNumber(match[3]),
                    function: match[4] ?? '',
                    raw: trimmed,
                }),
            );
            continue;
        }

        match = /^#\d+\s+(\[internal function\]|\{main\}):?\s*(.*)$/.exec(
            trimmed,
        );

        if (match) {
            frames.push(
                frame({
                    filename: match[1] === '{main}' ? '' : match[1],
                    function: match[1] === '{main}' ? '{main}' : match[2],
                    inApp: false,
                    raw: trimmed,
                }),
            );
            continue;
        }

        // Java: at com.acme.Billing.charge(Billing.java:42)
        match = /^at\s+([\w$.<>]+)\.([\w$<>]+)\(([^:()]+)(?::(\d+))?\)$/.exec(
            trimmed,
        );

        if (match) {
            frames.push(
                frame({
                    module: match[1],
                    function: `${match[1]}.${match[2]}`,
                    filename: match[3],
                    lineno: lineNumber(match[4]),
                    raw: trimmed,
                }),
            );
            continue;
        }

        // V8: at fn (file:12:3) / at file:12:3 / at async fn (file:12:3)
        match =
            /^at\s+(?:async\s+)?(.*?)\s*\((.+?):(\d+)(?::(\d+))?\)$/.exec(
                trimmed,
            ) ?? null;

        if (match) {
            frames.push(
                frame({
                    function: match[1],
                    filename: match[2],
                    lineno: lineNumber(match[3]),
                    colno: lineNumber(match[4]),
                    raw: trimmed,
                }),
            );
            continue;
        }

        match = /^at\s+(?:async\s+)?(.+?):(\d+)(?::(\d+))?$/.exec(trimmed);

        if (match) {
            frames.push(
                frame({
                    filename: match[1],
                    lineno: lineNumber(match[2]),
                    colno: lineNumber(match[3]),
                    raw: trimmed,
                }),
            );
            continue;
        }

        // Python: File "/app/x.py", line 12, in handler — the next line is the source.
        match = /^File "(.+?)", line (\d+)(?:, in (.+))?$/.exec(trimmed);

        if (match) {
            const next = lines[index + 1];
            const source =
                next !== undefined &&
                /^\s{4,}\S/.test(next) &&
                !/^\s*File "/.test(next)
                    ? next.trim()
                    : null;

            frames.push(
                frame({
                    filename: match[1],
                    lineno: lineNumber(match[2]),
                    function: match[3] ?? '',
                    contextLine: source,
                    raw: trimmed,
                }),
            );

            if (source !== null) {
                index++;
            }

            pythonOrder = true;
            continue;
        }

        // Ruby: /app/models/user.rb:12:in 'method'
        match = /^(.+?):(\d+):in [`'](.+)'$/.exec(trimmed);

        if (match) {
            frames.push(
                frame({
                    filename: match[1],
                    lineno: lineNumber(match[2]),
                    function: match[3],
                    raw: trimmed,
                }),
            );
            continue;
        }

        // Go: main.handler(...) then, indented, /app/main.go:12 +0x1d
        const next = lines[index + 1];
        const goLocation =
            next !== undefined
                ? /^\t(.+?\.go):(\d+)(?:\s+\+0x[0-9a-f]+)?$/.exec(next)
                : null;

        if (goLocation && /^[\w./*()-]+\(.*\)$/.test(trimmed)) {
            frames.push(
                frame({
                    function: trimmed.replace(/\(.*\)$/, ''),
                    filename: goLocation[1],
                    lineno: lineNumber(goLocation[2]),
                    raw: `${trimmed} ${next.trim()}`,
                }),
            );
            index++;
        }
    }

    return pythonOrder ? frames.reverse() : frames;
}

/**
 * The causes behind a printed exception, nearest first.
 *
 * A section's headline is its `Caused by:` / `Next ` line, or for Python the
 * `Type: message` line that closes the traceback.
 */
export function parseCausesFromText(text: string): ExceptionCause[] {
    return splitStackSections(text).causes.map((section) => {
        const lines = section.split(/\r?\n/);
        const python = lines.some((line) =>
            /^Traceback \(most recent call last\)/.test(line.trim()),
        );
        const header = python
            ? ([...lines]
                  .reverse()
                  .find((line) => line.trim() !== '' && !/^\s/.test(line)) ??
              '')
            : lines[0];
        const headline = header.replace(/^\s*(Caused by:|Next )\s*/, '').trim();
        const match = /^([\w$.\\]+)(?::\s*(.*))?$/.exec(headline);
        const stacktrace = python ? section : lines.slice(1).join('\n');
        const frames = parseFrames(stacktrace);

        return {
            type: match?.[1] ?? '',
            message: match?.[2] ?? headline,
            origin: frames[0] ? frameLocation(frames[0]) : '',
            stacktrace,
        };
    });
}

/**
 * Read the structured frames an SDK event carries, or null when there are none.
 */
export function parseFramesJson(
    value: string | undefined,
): StackFrame[] | null {
    const parsed = parseJson(value);

    if (!Array.isArray(parsed)) {
        return null;
    }

    return parsed
        .filter((item): item is Record<string, unknown> => isObject(item))
        .map((item) =>
            frame({
                filename: text(item.filename) || text(item.abs_path),
                function: text(item.function),
                module: text(item.module),
                lineno: typeof item.lineno === 'number' ? item.lineno : null,
                colno: typeof item.colno === 'number' ? item.colno : null,
                inApp:
                    typeof item.in_app === 'boolean' ? item.in_app : undefined,
                preContext: strings(item.pre_context),
                contextLine:
                    typeof item.context_line === 'string'
                        ? item.context_line
                        : null,
                postContext: strings(item.post_context),
                raw: '',
            }),
        );
}

/**
 * The frames to show for an event: structured when the sender sent them,
 * parsed from the stack text otherwise.
 */
export function eventFrames(
    entry: LogEntry | null,
    stacktrace: string,
): StackFrame[] {
    const structured = parseFramesJson(
        entry?.logAttributes['exception.frames'],
    );

    if (structured !== null && structured.length > 0) {
        return structured;
    }

    return parseStackTrace(stacktrace);
}

/**
 * The causes behind an event's exception, nearest first.
 */
export function eventCauses(
    entry: LogEntry | null,
    stacktrace: string,
): ExceptionCause[] {
    const parsed = parseJson(entry?.logAttributes['exception.chain']);

    if (Array.isArray(parsed)) {
        return parsed.filter(isObject).map((item) => ({
            type: text(item.type),
            message: text(item.message),
            origin: text(item.origin),
            stacktrace: text(item.stacktrace),
        }));
    }

    return parseCausesFromText(stacktrace);
}

/** `file:line:col` the way an editor accepts it. */
export function frameLocation(stackFrame: StackFrame): string {
    const parts = [stackFrame.filename || stackFrame.module || '<unknown>'];

    if (stackFrame.lineno !== null) {
        parts.push(String(stackFrame.lineno));
    }

    if (stackFrame.colno !== null) {
        parts.push(String(stackFrame.colno));
    }

    return parts.join(':');
}

/**
 * A location as it reads best on screen: a browser frame's file is a full
 * URL, and its scheme and host repeat on every line, so they are dropped.
 * The full value stays available (titles, copy, raw view).
 */
export function displayLocation(location: string): string {
    return location.replace(/\b[a-z][a-z0-9+.-]*:\/\/[^/\s]+(?=\/)/gi, '');
}

/** A frame has source to show when the SDK sent its context lines. */
export function hasContext(stackFrame: StackFrame): boolean {
    return stackFrame.contextLine !== null;
}

export type FrameGroup =
    | { kind: 'frame'; frame: StackFrame; index: number }
    | { kind: 'collapsed'; frames: { frame: StackFrame; index: number }[] };

/**
 * Fold runs of library frames into one expandable row.
 *
 * A stack is read for the application's own frames; forty framework frames
 * between them are noise until someone asks. A run of one is not worth a
 * fold, and when nothing is in-app there is nothing to fold towards, so the
 * whole stack is shown.
 */
export function groupFrames(frames: StackFrame[]): FrameGroup[] {
    if (!frames.some((item) => item.inApp)) {
        return frames.map((item, index) => ({
            kind: 'frame',
            frame: item,
            index,
        }));
    }

    const groups: FrameGroup[] = [];
    let run: { frame: StackFrame; index: number }[] = [];

    const flush = () => {
        if (run.length === 1) {
            groups.push({ kind: 'frame', ...run[0] });
        } else if (run.length > 1) {
            groups.push({ kind: 'collapsed', frames: run });
        }

        run = [];
    };

    frames.forEach((item, index) => {
        if (item.inApp) {
            flush();
            groups.push({ kind: 'frame', frame: item, index });
        } else {
            run.push({ frame: item, index });
        }
    });

    flush();

    return groups;
}

/*
|--------------------------------------------------------------------------
| Breadcrumbs and context
|--------------------------------------------------------------------------
*/

/**
 * The breadcrumbs an event carries, most recent first.
 *
 * Structured when the SDK path stored them; otherwise the plain text block,
 * one `[category] message` per line.
 */
export function eventBreadcrumbs(entry: LogEntry | null): Breadcrumb[] {
    const parsed = parseJson(entry?.logAttributes['breadcrumbs.json']);

    if (Array.isArray(parsed)) {
        return parsed.filter(isObject).map((item) => ({
            timestamp: text(item.timestamp),
            type: text(item.type),
            category: text(item.category),
            level: text(item.level),
            message: text(item.message),
            data: text(item.data),
        }));
    }

    const plain = entry?.logAttributes.breadcrumbs ?? '';

    return plain
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => {
            const match = /^\[([^\]]+)\]\s*(.*)$/.exec(line.trim());

            return {
                timestamp: '',
                type: '',
                category: match?.[1] ?? '',
                level: '',
                message: match?.[2] ?? line.trim(),
                data: '',
            };
        });
}

/**
 * The context panels the issue page lays out, and the keys each one reads.
 *
 * Everything an event says that is not in one of these, and is not the
 * exception itself, is listed under "All attributes".
 */
export const CONTEXT_SECTIONS: { title: string; keys: string[] }[] = [
    {
        title: 'Request',
        keys: [
            'http.request.method',
            'http.method',
            'http.url',
            'url.full',
            'url.path',
            'url.query',
            'http.route',
            'http.response.status_code',
            'http.status_code',
            'user_agent.original',
            'client.address',
            'transaction.name',
        ],
    },
    {
        title: 'User',
        keys: ['user.id', 'enduser.id', 'user.name', 'user.email', 'user.ip'],
    },
    {
        title: 'Runtime',
        keys: [
            'process.runtime.name',
            'process.runtime.version',
            'telemetry.sdk.language',
            'telemetry.sdk.name',
            'telemetry.sdk.version',
            'os.name',
            'os.version',
            'browser.name',
            'browser.version',
            'device.model',
            'device.family',
            'host.name',
        ],
    },
    {
        title: 'Release',
        keys: [
            'service.version',
            'service.dist',
            'deployment.environment',
            'deployment.environment.name',
        ],
    },
];

/** Attributes the page renders elsewhere, never in the generic list. */
const RENDERED_ELSEWHERE = new Set([
    'exception.type',
    'exception.message',
    'exception.stacktrace',
    'exception.frames',
    'exception.chain',
    'breadcrumbs',
    'breadcrumbs.json',
]);

/**
 * An event's attributes, the log record's winning over the resource's.
 */
export function mergedAttributes(
    entry: LogEntry | null,
): Record<string, string> {
    if (entry === null) {
        return {};
    }

    return { ...entry.resourceAttributes, ...entry.logAttributes };
}

/**
 * The context panels for one event, each holding only the keys it carries.
 */
export function contextSections(
    entry: LogEntry | null,
): { title: string; values: [string, string][] }[] {
    const attributes = mergedAttributes(entry);

    const fixed = CONTEXT_SECTIONS.map((section) => ({
        title: section.title,
        values: section.keys
            .filter((key) => (attributes[key] ?? '') !== '')
            .map((key): [string, string] => [key, attributes[key]]),
    }));

    /*
     * The application's own contexts (`setContext('order', …)` arrives as
     * `context.order.*`), one panel each, titled by the name it was set under.
     */
    const custom = new Map<string, [string, string][]>();

    for (const [key, value] of Object.entries(attributes).sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        const match = /^context\.([^.]+)\.(.+)$/.exec(key);

        if (match && value !== '') {
            custom.set(match[1], [
                ...(custom.get(match[1]) ?? []),
                [match[2], value],
            ]);
        }
    }

    return [
        ...fixed,
        ...[...custom.entries()].map(([name, values]) => ({
            title: name.charAt(0).toUpperCase() + name.slice(1),
            values,
        })),
    ].filter((section) => section.values.length > 0);
}

/**
 * The event's tags, as the SDK sent them (`tag.*`), plus `extra.*`.
 */
export function eventTags(entry: LogEntry | null): [string, string][] {
    return Object.entries(mergedAttributes(entry))
        .filter(([key]) => key.startsWith('tag.'))
        .map(([key, value]): [string, string] => [key.slice(4), value])
        .sort(([a], [b]) => a.localeCompare(b));
}

/**
 * Everything not shown in a panel of its own, sorted by key.
 */
export function remainingAttributes(
    entry: LogEntry | null,
): [string, string][] {
    const shown = new Set(CONTEXT_SECTIONS.flatMap((section) => section.keys));

    return Object.entries(mergedAttributes(entry))
        .filter(
            ([key]) =>
                !RENDERED_ELSEWHERE.has(key) &&
                !shown.has(key) &&
                !key.startsWith('tag.') &&
                !key.startsWith('context.'),
        )
        .sort(([a], [b]) => a.localeCompare(b));
}

/** What the tag breakdown calls each key. */
export const TAG_LABEL: Record<string, string> = {
    'deployment.environment': 'Environment',
    'service.version': 'Release',
    'service.name': 'Service',
    'host.name': 'Host',
    user: 'User',
    'http.request.method': 'Method',
    'browser.name': 'Browser',
    'os.name': 'OS',
    'process.runtime.name': 'Runtime',
    'telemetry.sdk.name': 'SDK',
};

function parseJson(value: string | undefined): unknown {
    if (value === undefined || value === '') {
        return null;
    }

    try {
        return JSON.parse(value) as unknown;
    } catch {
        return null;
    }
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function text(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function strings(value: unknown): string[] {
    return Array.isArray(value)
        ? value.filter((item): item is string => typeof item === 'string')
        : [];
}

/**
 * The list's filters as a query string, with `changes` applied.
 *
 * Defaults are left out so the plain list is a plain URL, and any change
 * other than the page itself starts again from page one — page four of a
 * different filter is not a place anyone meant to go.
 */
export function issueFilterQuery(
    filters: IssueFilters,
    changes: Partial<Record<keyof IssueFilters, string | number | null>> = {},
): Record<string, string> {
    const merged: Record<string, string | number | null> = {
        ...filters,
        ...(Object.keys(changes).some((key) => key !== 'page')
            ? { page: 1 }
            : {}),
        ...changes,
    };

    const defaults: Record<string, string | number> = {
        period: '7d',
        status: 'unresolved',
        sort: 'last_seen',
        page: 1,
    };

    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(merged)) {
        if (value === null || value === '' || defaults[key] === value) {
            continue;
        }

        query[key] = String(value);
    }

    return query;
}
