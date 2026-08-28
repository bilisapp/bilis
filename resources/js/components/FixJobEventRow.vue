<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Flag,
    MessageSquare,
    TerminalSquare,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import CodeCanvas from '@/components/CodeCanvas.vue';
import { cn } from '@/lib/utils';
import type { FixJobEvent, FixJobEventType } from '@/types';

/**
 * One line of an agent session, in the single schema Ayos uses for the live
 * stream and the persisted transcript alike.
 *
 * The row is deliberately literal: it shows what the agent said or ran, and
 * never paraphrases. Anything that is code — a tool's arguments, a test run,
 * a returned excerpt — goes through `CodeCanvas`, because that is the only
 * code renderer in the app.
 */
const props = defineProps<{
    event: FixJobEvent;
    /** Dim the connecting rule on the last row so the timeline ends cleanly. */
    last?: boolean;
}>();

const ICONS: Record<FixJobEventType, unknown> = {
    phase: Flag,
    agent_message: MessageSquare,
    tool_call: Wrench,
    tool_result: TerminalSquare,
    test_output: TerminalSquare,
    error: AlertTriangle,
    done: CheckCircle2,
};

const LABELS: Record<FixJobEventType, string> = {
    phase: 'Phase',
    agent_message: 'Agent',
    tool_call: 'Tool call',
    tool_result: 'Tool result',
    test_output: 'Tests',
    error: 'Error',
    done: 'Done',
};

const data = computed<Record<string, unknown>>(() => props.event.data ?? {});

/**
 * Pull the first string that carries the row's message, whatever the emitter
 * chose to call it. Ayos's per-type `data` is intentionally loose, and a
 * transcript that silently renders blank is worse than one that guesses.
 */
function firstString(keys: string[]): string | null {
    for (const key of keys) {
        const value = data.value[key];

        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return null;
}

const icon = computed(() => ICONS[props.event.type]);
const label = computed(() => LABELS[props.event.type] ?? props.event.type);

const headline = computed<string | null>(() => {
    switch (props.event.type) {
        case 'phase': {
            const state = firstString(['state', 'phase', 'name', 'message']);
            // Ayos closes a phase with a second event carrying its duration;
            // the state alone would render as an unexplained repeat.
            const duration = data.value.duration_ms;

            if (state && typeof duration === 'number') {
                return `${state} · ${(duration / 1000).toFixed(1)}s`;
            }

            return state;
        }
        case 'tool_call':
        case 'tool_result':
            return firstString(['title', 'name', 'tool', 'tool_name']);
        case 'test_output': {
            const passed = data.value.passed;

            return typeof passed === 'boolean'
                ? passed
                    ? 'passed'
                    : 'failed'
                : null;
        }
        case 'done':
            return firstString(['status', 'result', 'summary']);
        default:
            return null;
    }
});

const message = computed<string | null>(() => {
    // The queued phase names the hosts the sandbox may reach — the one piece
    // of a phase payload worth a sentence.
    if (props.event.type === 'phase') {
        const egress = data.value.egress;

        return Array.isArray(egress) && egress.length > 0
            ? `Egress: ${egress.join(', ')}`
            : null;
    }

    return firstString(['text', 'message', 'content', 'summary', 'reason']);
});

/**
 * The block of code this row shows, if any: a patch renders as a diff, a
 * command's output as plain text, a tool's arguments as JSON.
 */
const codeBlock = computed<{
    patch: string | null;
    code: string | null;
    filename: string;
} | null>(() => {
    const patch = firstString(['diff', 'patch']);

    if (patch) {
        return { patch, code: null, filename: 'change.patch' };
    }

    const output = firstString([
        'output',
        'output_tail',
        'stdout',
        'result',
        'excerpt',
    ]);

    if (output && props.event.type !== 'done') {
        return {
            patch: null,
            code: output,
            filename:
                props.event.type === 'test_output' ? 'tests.log' : 'output.txt',
        };
    }

    const input = data.value.input ?? data.value.arguments ?? data.value.args;

    if (input !== null && typeof input === 'object') {
        // A shell tool's argument is the command; show the command, not the
        // JSON envelope it arrived in.
        const command = (input as Record<string, unknown>).command;

        if (typeof command === 'string' && command.trim() !== '') {
            return { patch: null, code: command, filename: 'command.sh' };
        }

        // Pi announces a tool call before its arguments have streamed in; an
        // empty envelope is a placeholder, not something to render.
        if (Object.keys(input).length === 0) {
            return null;
        }

        return {
            patch: null,
            code: JSON.stringify(input, null, 2),
            filename: 'arguments.json',
        };
    }

    return null;
});

const timestamp = computed(() => {
    const parsed = new Date(props.event.ts);

    return Number.isNaN(parsed.getTime())
        ? props.event.ts
        : new Intl.DateTimeFormat(undefined, {
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
          }).format(parsed);
});

const isError = computed(() => props.event.type === 'error');
</script>

<template>
    <li
        class="relative flex gap-3 pb-4 last:pb-0"
        :data-event-type="event.type"
        data-test="fix-job-event"
    >
        <!--
          The rule is drawn behind the icons rather than between them, so a
          row of any height keeps the timeline continuous.
        -->
        <span
            v-if="!last"
            aria-hidden="true"
            class="absolute top-7 bottom-0 left-[13px] w-px bg-border"
        />

        <span
            :class="
                cn(
                    'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border bg-card',
                    isError ? 'text-severity-error' : 'text-muted-foreground',
                )
            "
        >
            <component :is="icon" class="size-3.5" />
        </span>

        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <span class="text-sm font-medium">{{ label }}</span>
                <span
                    v-if="headline"
                    class="font-mono text-xs text-muted-foreground"
                    data-test="fix-job-event-headline"
                    >{{ headline }}</span
                >
                <span class="ml-auto font-mono text-xs text-muted-foreground">
                    {{ timestamp }}
                </span>
            </div>

            <p
                v-if="message"
                :class="
                    cn(
                        'text-sm whitespace-pre-wrap',
                        isError ? 'text-severity-error' : 'text-foreground',
                    )
                "
                data-test="fix-job-event-message"
            >
                {{ message }}
            </p>

            <CodeCanvas
                v-if="codeBlock"
                :patch="codeBlock.patch"
                :code="codeBlock.code"
                :filename="codeBlock.filename"
                max-height="18rem"
                hide-header
            />
        </div>
    </li>
</template>
