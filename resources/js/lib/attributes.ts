import { formatDuration } from '@/lib/traces';

/**
 * How a value should be *drawn*, inferred from what the key says it is.
 *
 * A span's attributes arrive as a flat `Record<string, string>` — ClickHouse
 * stores them as `Map(String, String)` and every number, flag and stack trace
 * comes back stringified. Rendering them all as one undifferentiated column of
 * monospace is honest and useless: `235893`, `true` and a 40-line heredoc are
 * three different reading tasks wearing the same clothes.
 */
export type AttributeKind =
    | 'text'
    | 'code'
    | 'duration'
    | 'count'
    | 'status'
    | 'boolean'
    | 'timestamp'
    | 'identifier'
    | 'url';

export type SpanAttribute = {
    key: string;
    /** The namespace, dimmed: `gen_ai.usage.` of `gen_ai.usage.input_tokens`. */
    prefix: string;
    /** The last segment, which is the part that distinguishes one row. */
    name: string;
    /** The value exactly as stored — what a copy must yield. */
    value: string;
    /** The value as drawn, which may be reformatted. */
    display: string;
    kind: AttributeKind;
    /**
     * This attribute is the span saying something went wrong.
     *
     * The only thing here allowed a colour, and it borrows the span-failure
     * token the rest of this panel already uses rather than introducing one.
     */
    failed: boolean;
};

export type AttributeGroup = {
    id: string;
    title: string;
    attributes: SpanAttribute[];
    /**
     * Folded on arrival. Identity and environment are eight of an agent span's
     * twenty-nine keys and are never what a reader opened the panel for; they
     * stay present and one click away rather than being dropped or dumped.
     */
    collapsedByDefault: boolean;
};

/** A group, and the keys it claims. First group to claim a key wins it. */
type GroupDefinition = {
    id: string;
    title: string;
    collapsedByDefault?: boolean;
    claims: (key: string) => boolean;
};

const startsWithAny = (key: string, prefixes: string[]): boolean =>
    prefixes.some((prefix) => key === prefix || key.startsWith(`${prefix}.`));

const isOneOf = (key: string, keys: string[]): boolean => keys.includes(key);

/**
 * The groups, in reading order.
 *
 * Outcome leads because it answers the question that opened the panel — what
 * happened — and is two or three rows deep. Everything after it is ordered from
 * what the span was doing towards where it was running, which is the order a
 * reader narrows in: the work, then the machinery, then the paperwork.
 */
const GROUPS: GroupDefinition[] = [
    {
        id: 'outcome',
        title: 'Outcome',
        claims: (key) =>
            startsWithAny(key, ['exception', 'error']) ||
            isOneOf(key, [
                'success',
                'stop_reason',
                'decision',
                'source',
                'http.status_code',
                'http.response.status_code',
                'rpc.grpc.status_code',
                'db.response.status_code',
            ]),
    },
    {
        id: 'request',
        title: 'Request',
        claims: (key) =>
            startsWithAny(key, [
                'http',
                'url',
                'server',
                'client',
                'network',
                'user_agent',
            ]),
    },
    {
        id: 'database',
        title: 'Database',
        claims: (key) => startsWithAny(key, ['db']),
    },
    {
        id: 'messaging',
        title: 'Messaging',
        claims: (key) => startsWithAny(key, ['messaging']),
    },
    { id: 'rpc', title: 'RPC', claims: (key) => startsWithAny(key, ['rpc']) },
    {
        id: 'tool',
        title: 'Tool call',
        claims: (key) =>
            startsWithAny(key, ['gen_ai.tool']) ||
            isOneOf(key, [
                'tool_name',
                'tool_use_id',
                'full_command',
                'file_path',
                'subagent_type',
                'agent_id',
            ]),
    },
    {
        id: 'model',
        title: 'Model',
        claims: (key) =>
            startsWithAny(key, ['gen_ai', 'llm_request']) ||
            /_tokens$/.test(key) ||
            isOneOf(key, [
                'model',
                'ttft_ms',
                'speed',
                'attempt',
                'request_id',
                'client_request_id',
            ]),
    },
    {
        id: 'session',
        title: 'Session',
        claims: (key) =>
            startsWithAny(key, ['session', 'interaction', 'span']) ||
            /^user_prompt/.test(key) ||
            key === 'duration_ms',
    },
    {
        id: 'identity',
        title: 'Identity',
        collapsedByDefault: true,
        claims: (key) =>
            startsWithAny(key, ['user', 'organization', 'enduser', 'account']),
    },
    {
        id: 'environment',
        title: 'Environment',
        collapsedByDefault: true,
        claims: (key) =>
            startsWithAny(key, [
                'host',
                'service',
                'os',
                'process',
                'container',
                'k8s',
                'cloud',
                'deployment',
                'telemetry',
            ]) || key === 'terminal.type',
    },
];

const OTHER_GROUP = { id: 'other', title: 'Other' };

/** Keys whose value is a program, a query, or something a person wrote. */
const CODE_KEYS = [
    'full_command',
    'db.query.text',
    'db.statement',
    'user_prompt',
    'gen_ai.prompt',
    'gen_ai.completion',
    'gen_ai.tool.call.arguments',
    'exception.stacktrace',
    'code.stacktrace',
];

const ISO_TIMESTAMP = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/;
const HEX_ID = /^[0-9a-f]{16,}$/i;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * What kind of thing this attribute holds.
 *
 * The key decides wherever it can, because a key is a declaration and a value
 * is a coincidence: `2` is a token count under `input_tokens` and a retry under
 * `attempt`, and no amount of looking at `2` will tell them apart.
 */
function attributeKind(key: string, value: string): AttributeKind {
    if (CODE_KEYS.includes(key) || value.includes('\n') || value.length > 120) {
        return 'code';
    }

    if (value === 'true' || value === 'false') {
        return 'boolean';
    }

    if (/status_code$/.test(key)) {
        return 'status';
    }

    if (/(^|[._])duration(_ms)?$/.test(key) || /_ms$/.test(key)) {
        return 'duration';
    }

    if (
        /_tokens$/.test(key) ||
        /(^|[._])(count|size|length|attempt|sequence)$/.test(key)
    ) {
        return 'count';
    }

    if (ISO_TIMESTAMP.test(value)) {
        return 'timestamp';
    }

    if (/^https?:\/\//.test(value)) {
        return 'url';
    }

    if (
        UUID.test(value) ||
        HEX_ID.test(value) ||
        /(^|[._])(id|uuid|guid)$/.test(key) ||
        /_(id|uuid)$/.test(key)
    ) {
        return 'identifier';
    }

    return 'text';
}

const NUMBER_FORMAT = new Intl.NumberFormat('en-US');

/**
 * The value as drawn. Only ever a reformatting — never a truncation.
 *
 * Shortening happens in CSS so that selecting a value still yields the value.
 */
function formatValue(kind: AttributeKind, value: string): string {
    const numeric = Number(value);

    if (kind === 'duration' && Number.isFinite(numeric)) {
        return formatDuration(numeric);
    }

    if (kind === 'count' && Number.isFinite(numeric)) {
        return NUMBER_FORMAT.format(numeric);
    }

    return value;
}

/**
 * Whether this attribute is the span reporting a failure.
 *
 * Narrow on purpose. `error` and `success` are unambiguous, an `exception.*`
 * key only exists because one was thrown, and a 4xx/5xx is a failure by
 * definition — anything vaguer stays achromatic, because a panel that tints
 * half its rows has stopped pointing at anything.
 */
function isFailure(key: string, value: string): boolean {
    if (key === 'error') {
        return value !== '' && value !== 'false';
    }

    if (key === 'success') {
        return value === 'false';
    }

    if (key.startsWith('exception.')) {
        return true;
    }

    if (/status_code$/.test(key)) {
        return Number(value) >= 400;
    }

    return false;
}

/** Split a dotted key into the namespace and the segment that identifies it. */
function splitKey(key: string): { prefix: string; name: string } {
    const boundary = key.lastIndexOf('.');

    return boundary === -1
        ? { prefix: '', name: key }
        : {
              prefix: `${key.slice(0, boundary)}.`,
              name: key.slice(boundary + 1),
          };
}

export function describeAttribute(key: string, value: string): SpanAttribute {
    const kind = attributeKind(key, value);

    return {
        key,
        ...splitKey(key),
        value,
        display: formatValue(kind, value),
        kind,
        failed: isFailure(key, value),
    };
}

/**
 * Sort a span's attributes into groups, dropping the ones that stayed empty.
 *
 * Within a group the original key order is kept rather than sorted: an exporter
 * emits related keys together, and alphabetising splits `input_tokens` from
 * `output_tokens` to put `model` between them.
 */
export function groupAttributes(
    attributes: Record<string, string>,
    query = '',
): AttributeGroup[] {
    const needle = query.trim().toLowerCase();

    const matching = Object.entries(attributes).filter(
        ([key, value]) =>
            needle === '' ||
            key.toLowerCase().includes(needle) ||
            value.toLowerCase().includes(needle),
    );

    const groups = new Map<string, SpanAttribute[]>();

    for (const [key, value] of matching) {
        const group = GROUPS.find((candidate) => candidate.claims(key));
        const id = group?.id ?? OTHER_GROUP.id;

        groups.set(id, [
            ...(groups.get(id) ?? []),
            describeAttribute(key, value),
        ]);
    }

    return [...GROUPS, OTHER_GROUP]
        .filter((group) => groups.has(group.id))
        .map((group) => ({
            id: group.id,
            title: group.title,
            attributes: groups.get(group.id) ?? [],
            collapsedByDefault:
                'collapsedByDefault' in group
                    ? (group.collapsedByDefault ?? false)
                    : false,
        }));
}
