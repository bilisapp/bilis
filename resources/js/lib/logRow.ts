import { BRAND_ICON_PATHS } from '@/lib/brandIcons';
import { formatUtcTimestamp } from '@/lib/logs';
import type { LogAutofixState, LogAutofixTarget, LogEntry } from '@/types';

/**
 * How much of one log line travels inside an assistant URL.
 *
 * Browsers take far longer URLs than this, but the receiving app is a web
 * server with a header limit of its own, and a truncated prompt that silently
 * loses its stack trace is worse than a short one that says so. The clipboard
 * copy is never truncated — that is the escape hatch for a very long trace.
 */
export const PROMPT_LIMIT = 6000;

/**
 * The assistants the "Ask AI" menu can hand a log line to.
 *
 * `url` returning null means the assistant has no documented way to be opened
 * with a question already in it, so the prompt goes to the clipboard and the
 * app opens empty — announced as such rather than silently dropping the text.
 *
 * Each carries its own mark rather than a shared external-link glyph: three
 * items that look identical make the reader read three labels, and these are
 * the three logos everybody already recognises. They are drawn in the menu's
 * own ink, never in the brand's colour.
 */
export const AI_ASSISTANTS: {
    id: string;
    label: string;
    /** The URL to open, or null when the prompt must be pasted by hand. */
    url: (prompt: string) => string | null;
    home: string;
    /** The brand mark, as one path on a 24x24 viewBox. */
    icon: string;
}[] = [
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        url: (prompt) => `https://chatgpt.com/?q=${encodeURIComponent(prompt)}`,
        home: 'https://chatgpt.com/',
        icon: BRAND_ICON_PATHS.openai,
    },
    {
        id: 'claude',
        label: 'Claude',
        url: (prompt) =>
            `https://claude.ai/new?q=${encodeURIComponent(prompt)}`,
        home: 'https://claude.ai/new',
        icon: BRAND_ICON_PATHS.claude,
    },
    {
        id: 'gemini',
        label: 'Gemini',
        // Gemini has no prefill parameter, so the prompt is copied instead.
        url: () => null,
        home: 'https://gemini.google.com/app',
        icon: BRAND_ICON_PATHS.gemini,
    },
];

export type AiAssistant = (typeof AI_ASSISTANTS)[number];

/**
 * Which codebase, if any, would fix the error on this line.
 *
 * Reads the server's map and applies the one rule that map encodes: a service
 * claimed by name beats the catch-all. Returns null when autofix is switched
 * off for the deployment, which is the difference between "no repository yet"
 * (an offer worth making) and "this feature does not exist here".
 */
export function autofixTargetFor(
    entry: LogEntry,
    state: LogAutofixState | undefined,
): LogAutofixTarget | null {
    if (!state?.enabled) {
        return null;
    }

    const project = state.projects[entry.projectId] ?? null;

    if (project === null) {
        return { project: null, repository: null };
    }

    return {
        project,
        repository:
            project.services[entry.serviceName] ?? project.catchAll ?? null,
    };
}

/**
 * One log line as plain text, for pasting into an issue or a chat.
 *
 * UTC rather than local time: what someone pastes elsewhere has to mean the
 * same thing to whoever reads it, and UTC is what the row is stored in.
 */
export function formatLogEntry(entry: LogEntry): string {
    const lines = [
        `${formatUtcTimestamp(entry.timestamp)}  ${(entry.severityText || 'LOG').toUpperCase()}  ${entry.serviceName || '—'}`,
        entry.body,
    ];

    const fields: [string, string][] = [
        ['trace_id', entry.traceId],
        ['span_id', entry.spanId],
        [
            'scope',
            entry.scopeName && entry.scopeVersion
                ? `${entry.scopeName}@${entry.scopeVersion}`
                : entry.scopeName,
        ],
    ];

    const detail = fields
        .filter(([, value]) => Boolean(value))
        .map(([key, value]) => `${key}: ${value}`);

    const attributes = [
        ...attributeLines('log attributes', entry.logAttributes),
        ...attributeLines('resource attributes', entry.resourceAttributes),
    ];

    return [...lines, ...detail, ...attributes].join('\n');
}

/**
 * The question an assistant is handed, with the log line as its evidence.
 *
 * The framing is deliberate: it asks for a cause and the smallest fix, which
 * is the same thing the autofix agent is asked for. Somebody who has no
 * repository connected should get an answer shaped like the one they would
 * have got from the feature they do not have yet.
 */
export function askAiPrompt(entry: LogEntry, limit = PROMPT_LIMIT): string {
    const prompt = [
        'I am debugging this error from my production logs. What is the most likely cause, and what is the smallest change that would fix it?',
        '',
        'Log line:',
        '',
        formatLogEntry(entry),
    ].join('\n');

    if (prompt.length <= limit) {
        return prompt;
    }

    return `${prompt.slice(0, limit - 32).trimEnd()}\n… (log line truncated)`;
}

/**
 * The attribute bag as `key: value` lines under a heading, or nothing.
 */
function attributeLines(
    title: string,
    values: Record<string, string>,
): string[] {
    const entries = Object.entries(values ?? {});

    if (entries.length === 0) {
        return [];
    }

    return [
        `${title}:`,
        ...entries.map(([key, value]) => `  ${key}: ${value}`),
    ];
}
