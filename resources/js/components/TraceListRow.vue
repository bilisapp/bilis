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
    <component
        :is="expired ? 'div' : Link"
        v-bind="expired ? {} : { href }"
        :class="
            cn(
                'group/row grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 border-b px-4 py-3 text-xs sm:grid-cols-[minmax(0,1fr)_7rem_5rem_5rem_10rem]',
                expired
                    ? 'cursor-default opacity-70'
                    : 'transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none',
                fresh &&
                    'animate-in duration-300 ease-out fade-in slide-in-from-top-1 motion-reduce:animate-none',
                // The keyboard cursor reads as a held row: a ring that carries
                // the state without depending on colour, the same treatment the
                // log stream gives its cursor row.
                cursor && 'bg-accent/70 ring-1 ring-ring/60 ring-inset',
            )
        "
        :data-test="`trace-row-${trace.traceId}`"
        :data-cursor="cursor ? 'true' : undefined"
        :aria-disabled="expired ? 'true' : undefined"
    >
        <div class="flex min-w-0 flex-col gap-0.5">
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
                            'truncate font-medium',
                            !trace.rootName && 'text-muted-foreground italic',
                        )
                    "
                >
                    {{ operation }}
                </span>
            </div>

            <div class="flex min-w-0 items-center gap-2 text-muted-foreground">
                <span v-if="trace.rootService" class="truncate">
                    {{ trace.rootService }}
                </span>
                <span v-if="trace.rootService" aria-hidden="true">·</span>
                <span
                    class="inline-flex min-w-0 items-center gap-1 font-mono"
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
                                'inline-flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
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
                    'text-right font-mono tabular-nums sm:text-left',
                    durationClass(trace.durationMs),
                )
            "
        >
            {{ formatDuration(trace.durationMs) }}
        </div>

        <div class="hidden text-muted-foreground tabular-nums sm:block">
            {{ trace.spanCount }}
            <span>{{ trace.spanCount === 1 ? 'span' : 'spans' }}</span>
        </div>

        <div class="hidden tabular-nums sm:block">
            <span v-if="failed" class="text-severity-error">
                {{ trace.errorCount }} error{{
                    trace.errorCount === 1 ? '' : 's'
                }}
            </span>
            <span v-else class="text-muted-foreground">—</span>
        </div>

        <div
            class="hidden text-muted-foreground sm:block"
            :title="formatUtcTimestamp(trace.startedAt)"
        >
            {{ formatTimestamp(trace.startedAt) }}
        </div>

        <p
            v-if="expired"
            class="col-span-full text-muted-foreground"
            data-test="trace-row-expired"
        >
            Spans for this trace have passed the 30-day retention window. The
            summary is kept for 90 days, so the shape of the trace survives even
            though its detail does not.
        </p>
    </component>
</template>
