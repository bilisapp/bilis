<script setup lang="ts">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import LogRowActions from '@/components/LogRowActions.vue';
import {
    formatTimestamp,
    formatUtcTimestamp,
    SEVERITY_DOT_CLASS,
    SEVERITY_EDGE_CLASS,
    SEVERITY_ROW_CLASS,
    SEVERITY_TEXT_CLASS,
    severityLevelFor,
    timeZoneOffset,
} from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { LogAutofixTarget, LogEntry, TeamLlmCredential } from '@/types';

const props = withDefaults(
    defineProps<{
        entry: LogEntry;
        expanded: boolean;
        /** Arrived in the last live-tail poll; announces itself once. */
        fresh?: boolean;
        /** The row the keyboard is currently on. */
        cursor?: boolean;
        /**
         * The team this row is being read in. Absent in the styleguide, which
         * renders the row without its actions.
         */
        teamSlug?: string;
        /**
         * What this line resolves to for autofix, or null when the feature is
         * switched off for the deployment.
         */
        autofix?: LogAutofixTarget | null;
        credentials?: TeamLlmCredential[];
    }>(),
    {
        fresh: false,
        cursor: false,
        teamSlug: undefined,
        autofix: null,
        credentials: undefined,
    },
);

const emit = defineEmits<{
    (event: 'toggle'): void;
    (event: 'copied'): void;
    /** Forwarded from the actions cluster; the page owns the trace panel. */
    (event: 'trace', traceId: string): void;
}>();

const level = computed(() => severityLevelFor(props.entry));

const label = computed(
    () => props.entry.severityText || level.value.toUpperCase(),
);

const attributeGroups = computed(() => [
    { title: 'Log attributes', values: props.entry.logAttributes },
    { title: 'Resource attributes', values: props.entry.resourceAttributes },
]);
</script>

<template>
    <div
        :class="
            cn(
                'border-b border-l border-b-sidebar-border/70 text-xs dark:border-b-sidebar-border',
                SEVERITY_EDGE_CLASS[level],
                SEVERITY_ROW_CLASS[level],
                fresh &&
                    'animate-in duration-300 ease-out fade-in slide-in-from-top-1 motion-reduce:animate-none',
                // The keyboard cursor reads as a held row: the severity edge
                // at full weight, plus a ring that carries the state without
                // depending on colour.
                cursor &&
                    'border-l-[3px] bg-accent/70 ring-1 ring-ring/60 ring-inset',
            )
        "
        :data-severity="level"
        data-test="log-row"
    >
        <!--
          The actions sit beside the disclosure button rather than inside it —
          a button may not contain buttons — and they float ABOVE the row
          rather than taking a column of it. Reserving the width would shorten
          every log line on the page to make room for a control that is
          invisible most of the time; the line is what people came to read, so
          it gets the full width and the cluster slides over its tail on hover.
        -->
        <div
            class="group/row relative flex w-full items-start hover:bg-accent/50"
        >
            <button
                type="button"
                class="flex min-w-0 flex-1 items-start gap-3 px-3 py-1.5 text-left font-mono"
                :aria-expanded="expanded"
                @click="emit('toggle')"
            >
                <component
                    :is="expanded ? ChevronDown : ChevronRight"
                    class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                />

                <!--
              Local time to read against, UTC one hover away: the stored value
              is naive UTC, and that is what a reader pastes into a query.
            -->
                <span
                    class="shrink-0 text-muted-foreground tabular-nums"
                    :title="formatUtcTimestamp(entry.timestamp)"
                >
                    {{ formatTimestamp(entry.timestamp) }}
                    <!--
                  The row's own offset, softer than the time it qualifies.
                  Per instant, not per session: rows straddling a DST switch
                  wear the offset that was true for them.
                -->
                    <span class="opacity-60">{{
                        timeZoneOffset(entry.timestamp)
                    }}</span>
                </span>

                <span
                    :class="
                        cn(
                            'inline-flex w-16 shrink-0 items-center gap-1.5 font-semibold uppercase',
                            SEVERITY_TEXT_CLASS[level],
                        )
                    "
                >
                    <span
                        :class="
                            cn(
                                'size-2 shrink-0 rounded-full',
                                SEVERITY_DOT_CLASS[level],
                            )
                        "
                    />
                    {{ label }}
                </span>

                <span class="w-40 shrink-0 truncate text-muted-foreground">
                    {{ entry.serviceName || '—' }}
                </span>

                <span :class="cn('min-w-0 flex-1', expanded ? '' : 'truncate')">
                    {{ entry.body }}
                </span>
            </button>

            <LogRowActions
                v-if="teamSlug"
                :entry="entry"
                :team-slug="teamSlug"
                :autofix="autofix"
                :credentials="credentials"
                @trace="emit('trace', $event)"
                :class="
                    cn(
                        // Floating, so it costs the log line no width. Its own
                        // surface and hairline lift it off whatever severity
                        // tint is underneath, and the blur keeps the text it
                        // covers legible as texture rather than as words.
                        'absolute top-0.5 right-1.5 z-10 rounded-md border bg-background/90 px-1 py-0.5 shadow-xs backdrop-blur-sm',
                        // Hidden means untouchable: an invisible overlay must
                        // not swallow clicks aimed at the line beneath it.
                        'pointer-events-none opacity-0 transition-opacity',
                        'group-hover/row:pointer-events-auto group-hover/row:opacity-100',
                        'focus-within:pointer-events-auto focus-within:opacity-100',
                        (expanded || cursor) &&
                            'pointer-events-auto opacity-100',
                    )
                "
                @copied="emit('copied')"
            />
        </div>

        <div v-if="expanded" class="space-y-3 bg-muted/40 px-10 py-3 font-mono">
            <pre class="break-words whitespace-pre-wrap">{{ entry.body }}</pre>

            <dl class="grid gap-1 sm:grid-cols-2">
                <div v-if="entry.traceId" class="flex gap-2">
                    <dt class="text-muted-foreground">trace_id</dt>
                    <dd class="break-all">{{ entry.traceId }}</dd>
                </div>
                <div v-if="entry.spanId" class="flex gap-2">
                    <dt class="text-muted-foreground">span_id</dt>
                    <dd class="break-all">{{ entry.spanId }}</dd>
                </div>
                <div v-if="entry.scopeName" class="flex gap-2">
                    <dt class="text-muted-foreground">scope</dt>
                    <dd class="break-all">
                        {{ entry.scopeName }}
                        <template v-if="entry.scopeVersion">
                            @{{ entry.scopeVersion }}
                        </template>
                    </dd>
                </div>
            </dl>

            <div
                v-for="group in attributeGroups"
                :key="group.title"
                class="space-y-1"
            >
                <p
                    class="font-sans text-[11px] font-medium text-muted-foreground"
                >
                    {{ group.title }}
                </p>
                <p
                    v-if="Object.keys(group.values).length === 0"
                    class="text-muted-foreground"
                >
                    —
                </p>
                <dl v-else class="grid gap-1 sm:grid-cols-2">
                    <div
                        v-for="(value, key) in group.values"
                        :key="key"
                        class="flex gap-2"
                    >
                        <dt class="shrink-0 text-muted-foreground">
                            {{ key }}
                        </dt>
                        <dd class="break-all">{{ value }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
