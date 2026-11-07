<script setup lang="ts">
import { computed } from 'vue';
import LogRowActions from '@/components/LogRowActions.vue';
import {
    formatLocalTime,
    formatUtcTimestamp,
    SEVERITY_EDGE_CLASS,
    SEVERITY_TEXT_CLASS,
    severityLevelFor,
    splitExceptionHeadline,
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

/** An exception class at the head of the body is set heavier than the rest. */
const headline = computed(() => splitExceptionHeadline(props.entry.body));

const attributeGroups = computed(() => [
    { title: 'Log attributes', values: props.entry.logAttributes },
    { title: 'Resource attributes', values: props.entry.resourceAttributes },
]);
</script>

<template>
    <!--
      Colour on a row is one thing: the severity stripe down its left edge, so
      the edge of the stream reads as a temperature ribbon and a burst of red
      is visible before a line is read. The level's name repeats it in text.
      There is no resting wash — the stream stays on the surface.
    -->
    <div
        :class="
            cn(
                'border-b border-l-2 border-b-border/60 text-xs',
                SEVERITY_EDGE_CLASS[level],
                fresh &&
                    'animate-in duration-300 ease-out fade-in slide-in-from-top-1 motion-reduce:animate-none',
                (expanded || cursor) && 'border-l-[3px] bg-muted/50',
                cursor && 'ring-1 ring-ring/50 ring-inset',
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
            class="group/row relative flex w-full items-start hover:bg-muted/50"
        >
            <button
                type="button"
                class="flex min-w-0 flex-1 items-baseline gap-5 py-1.5 pr-4 pl-[calc(1rem-2px)] text-left font-mono text-xs leading-5 md:pr-10 md:pl-[calc(2.5rem-2px)]"
                :aria-expanded="expanded"
                @click="emit('toggle')"
            >
                <!--
                  Local time to read against, UTC one hover away: the stored
                  value is naive UTC, and that is what a reader pastes into a
                  query. The date is the stream's divider, not every row's.
                -->
                <span
                    class="w-[6.25rem] shrink-0 text-muted-foreground tabular-nums"
                    :title="`${formatUtcTimestamp(entry.timestamp)} · ${timeZoneOffset(entry.timestamp)}`"
                >
                    {{ formatLocalTime(entry.timestamp) }}
                </span>

                <span
                    :class="
                        cn(
                            'w-11 shrink-0 text-[11px] font-semibold tracking-[0.04em] uppercase',
                            SEVERITY_TEXT_CLASS[level],
                        )
                    "
                >
                    {{ label }}
                </span>

                <span
                    class="hidden w-32 shrink-0 truncate text-muted-foreground sm:block"
                >
                    {{ entry.serviceName || '—' }}
                </span>

                <span
                    :class="
                        cn(
                            'min-w-0 flex-1 text-foreground/80',
                            expanded ? 'break-words' : 'truncate',
                        )
                    "
                >
                    <span
                        v-if="headline.head"
                        class="font-semibold text-foreground"
                        >{{ headline.head }}</span
                    >
                    <template v-if="headline.head">&nbsp;</template
                    >{{ headline.rest }}
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
                        // surface and hairline lift it off the row, and the
                        // blur keeps the text it covers legible as texture
                        // rather than as words.
                        'absolute top-0.5 right-3 z-10 rounded-md border bg-card/90 px-1 py-0.5 backdrop-blur-sm md:right-8',
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

        <div
            v-if="expanded"
            class="space-y-4 pt-2 pr-4 pb-4 pl-4 font-mono text-xs md:pr-10 md:pl-[calc(2.5rem+6.25rem+2.75rem+2.5rem)]"
        >
            <pre
                class="leading-5 break-words whitespace-pre-wrap text-foreground"
                >{{ entry.body }}</pre>

            <dl
                v-if="entry.traceId || entry.spanId || entry.scopeName"
                class="grid gap-x-8 gap-y-1 sm:grid-cols-2"
            >
                <div v-if="entry.traceId" class="flex gap-3">
                    <dt class="w-28 shrink-0 text-muted-foreground">
                        trace_id
                    </dt>
                    <dd class="break-all">{{ entry.traceId }}</dd>
                </div>
                <div v-if="entry.spanId" class="flex gap-3">
                    <dt class="w-28 shrink-0 text-muted-foreground">span_id</dt>
                    <dd class="break-all">{{ entry.spanId }}</dd>
                </div>
                <div v-if="entry.scopeName" class="flex gap-3">
                    <dt class="w-28 shrink-0 text-muted-foreground">scope</dt>
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
                class="space-y-1.5"
            >
                <p class="font-sans eyebrow">
                    {{ group.title }}
                </p>
                <p
                    v-if="Object.keys(group.values).length === 0"
                    class="text-muted-foreground"
                >
                    —
                </p>
                <dl v-else class="grid gap-x-8 gap-y-1 sm:grid-cols-2">
                    <div
                        v-for="(value, key) in group.values"
                        :key="key"
                        class="flex min-w-0 gap-3"
                    >
                        <dt
                            class="w-44 shrink-0 truncate text-muted-foreground"
                            :title="String(key)"
                        >
                            {{ key }}
                        </dt>
                        <dd class="min-w-0 break-all text-foreground">
                            {{ value }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</template>
