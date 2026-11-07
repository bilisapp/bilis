<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed } from 'vue';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import { durationClass, formatDuration, traceHref } from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { TraceSummary } from '@/types';

const props = defineProps<{
    trace: TraceSummary;
    teamSlug: string;
    /**
     * The trace's spans have aged out from under its summary. Summaries live 90
     * days and spans 30, so this is a designed state rather than a fault — the
     * row stays, the waterfall link does not.
     */
    expired?: boolean;
    /** Arrived in the last live poll; announces itself once and then settles. */
    fresh?: boolean;
    /** The row the keyboard is currently on. */
    cursor?: boolean;
}>();

const failed = computed(() => props.trace.errorCount > 0);

/**
 * The waterfall link carries the trace's own start time.
 *
 * This is the single highest-leverage detail on the page: with `ts` the span
 * query is bounded to a few minutes, without it ClickHouse is asked to find a
 * trace id somewhere in thirty days of spans. Built through `traceHref()` so
 * it encodes the way every other link into a waterfall does.
 */
const href = computed(() =>
    traceHref(props.teamSlug, props.trace.traceId, {
        ts: props.trace.startedAt,
    }),
);

const { copy, copied } = useClipboard({
    copiedDuring: 1_500,
    // navigator.clipboard needs a secure context; self-hosted installs often
    // run plain http, so fall back to the legacy execCommand path there.
    legacy: true,
});

/**
 * Put the full id on the clipboard without following the row's link.
 *
 * The row shows sixteen of the id's thirty-two characters — enough to tell
 * rows apart, not enough to paste into a search — so the copy is the whole id.
 */
function copyId(event: Event) {
    event.preventDefault();
    event.stopPropagation();
    copy(props.trace.traceId);
}

const operation = computed(
    () => props.trace.rootName || 'Root span not received',
);
</script>

<template>
    <!--
      The grid template is shared with the list header in pages/traces/Index.vue
      so the numeric lanes line up from header to last row.
    -->
    <component
        :is="expired ? 'div' : Link"
        v-bind="expired ? {} : { href }"
        :class="
            cn(
                'group/row grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 border-b border-border/70 px-4 py-4 md:grid-cols-[minmax(0,1fr)_6rem_5rem_5rem_11rem] md:px-10',
                expired
                    ? 'cursor-default opacity-70'
                    : 'transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none',
                fresh &&
                    'animate-in duration-300 ease-out fade-in slide-in-from-top-1 motion-reduce:animate-none',
                // The keyboard cursor reads as a held row: a ring that carries
                // the state without depending on colour, the same treatment the
                // log stream and the issue list give their cursor rows.
                cursor && 'bg-muted/70 ring-1 ring-ring/50 ring-inset',
            )
        "
        :data-test="`trace-row-${trace.traceId}`"
        :data-cursor="cursor ? 'true' : undefined"
        :aria-disabled="expired ? 'true' : undefined"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                <!--
                  Colour is reserved for data. A failed trace takes the severity
                  error token, the same one an error log line wears; everything
                  else stays achromatic.
                -->
                <span
                    :class="
                        cn(
                            'size-1.5 shrink-0 rounded-full',
                            failed
                                ? 'bg-severity-error'
                                : 'bg-muted-foreground/40',
                        )
                    "
                    aria-hidden="true"
                />
                <span
                    :class="
                        cn(
                            'truncate font-mono text-sm font-medium',
                            trace.rootName
                                ? 'text-foreground'
                                : 'font-sans text-muted-foreground italic',
                        )
                    "
                    :title="trace.rootName || undefined"
                >
                    {{ operation }}
                </span>
            </div>

            <div
                class="flex min-w-0 items-center gap-1.5 pl-3.5 font-mono text-xs text-muted-foreground"
            >
                <span
                    v-if="trace.rootService"
                    class="truncate text-foreground/75"
                >
                    {{ trace.rootService }}
                </span>
                <span
                    v-if="trace.rootService"
                    class="text-input"
                    aria-hidden="true"
                    >·</span
                >
                <span
                    class="inline-flex min-w-0 items-center gap-1"
                    :title="trace.traceId"
                >
                    <span class="truncate">{{
                        trace.traceId.slice(0, 16)
                    }}</span>
                    <!--
                      Quiet until the row is hovered, focused or held by the
                      cursor, so it never competes with the row itself — the
                      same pattern as the log row's action cluster.
                    -->
                    <button
                        type="button"
                        :class="
                            cn(
                                'inline-flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 hover:bg-muted hover:text-foreground focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                (copied || cursor) && 'opacity-100',
                            )
                        "
                        :title="copied ? 'Copied' : 'Copy trace ID'"
                        :aria-label="copied ? 'Copied' : 'Copy trace ID'"
                        data-test="trace-row-copy"
                        @click="copyId"
                    >
                        <component :is="copied ? Check : Copy" class="size-3" />
                    </button>
                </span>
            </div>
        </div>

        <!--
          The duration carries its own magnitude, so a slow trace is visible
          before it is read. A failed trace still says so in severity-error on
          the row's dot and its error count: "this broke" and "this was slow"
          are different facts and each keeps its own family.
        -->
        <div
            :class="
                cn(
                    'text-right font-mono text-sm font-medium tabular-nums',
                    durationClass(trace.durationMs),
                )
            "
        >
            {{ formatDuration(trace.durationMs) }}
        </div>

        <div
            class="hidden text-right font-mono text-sm text-foreground/75 tabular-nums md:block"
            :title="`${trace.spanCount} ${trace.spanCount === 1 ? 'span' : 'spans'}`"
        >
            {{ trace.spanCount.toLocaleString() }}
        </div>

        <div
            class="hidden text-right font-mono text-sm tabular-nums md:block"
            :title="
                failed
                    ? `${trace.errorCount} error${trace.errorCount === 1 ? '' : 's'}`
                    : 'No errors'
            "
        >
            <span v-if="failed" class="font-medium text-severity-error">
                {{ trace.errorCount.toLocaleString() }}
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </div>

        <div
            class="hidden truncate text-right font-mono text-xs text-muted-foreground tabular-nums md:block"
            :title="formatUtcTimestamp(trace.startedAt)"
        >
            {{ formatTimestamp(trace.startedAt) }}
        </div>

        <p
            v-if="expired"
            class="col-span-full max-w-prose pl-3.5 text-xs text-muted-foreground"
            data-test="trace-row-expired"
        >
            Spans for this trace have passed the 30-day retention window. The
            summary is kept for 90 days, so the shape of the trace survives even
            though its detail does not.
        </p>
    </component>
</template>
