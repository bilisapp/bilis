<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import { formatDuration } from '@/lib/traces';
import { cn } from '@/lib/utils';
import { show as traceShow } from '@/routes/traces';
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
}>();

const failed = computed(() => props.trace.errorCount > 0);

/**
 * The waterfall link carries the trace's own start time.
 *
 * This is the single highest-leverage detail on the page: with `ts` the span
 * query is bounded to a few minutes, without it ClickHouse is asked to find a
 * trace id somewhere in thirty days of spans.
 */
const href = computed(() =>
    traceShow({
        current_team: props.teamSlug,
        trace: props.trace.traceId,
    }).url.concat(`?ts=${encodeURIComponent(props.trace.startedAt)}`),
);

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
                'grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 border-b px-4 py-3 text-xs sm:grid-cols-[minmax(0,1fr)_7rem_5rem_5rem_10rem]',
                expired
                    ? 'cursor-default opacity-70'
                    : 'transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none',
                fresh &&
                    'animate-in duration-300 ease-out fade-in slide-in-from-top-1 motion-reduce:animate-none',
            )
        "
        :data-test="`trace-row-${trace.traceId}`"
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
                <span class="truncate font-mono">
                    {{ trace.traceId.slice(0, 16) }}
                </span>
            </div>
        </div>

        <div class="text-right font-mono tabular-nums sm:text-left">
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
