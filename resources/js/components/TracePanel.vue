<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowUpRight, X } from '@lucide/vue';
import { computed } from 'vue';
import SpanWaterfall from '@/components/SpanWaterfall.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import {
    formatDuration,
    SPAN_STATUS_BAR_CLASS,
    SPAN_STATUS_TEXT_CLASS,
    traceHref,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { TracePanelResult } from '@/types';

const props = defineProps<{
    /** The trace being previewed; the panel is open whenever this is set. */
    traceId: string;
    teamSlug: string;
    /** Absent while the request is still in flight. */
    result?: TracePanelResult | null;
    /** The request failed outright — a network error, not a busy ClickHouse. */
    failed?: boolean;
}>();

const emit = defineEmits<{ (event: 'close'): void }>();

const summary = computed(() => props.result?.summary ?? null);

const failedTrace = computed(() => (summary.value?.errorCount ?? 0) > 0);

/**
 * The trace's spans have aged out from under its summary.
 *
 * Summaries live 90 days and spans 30, so this is a designed state rather than
 * a fault — and the same wording the trace page uses, because it is the same
 * fact. "Go to detail" stays live: the page explains it too, and a reader who
 * wants the long version should be able to get there.
 */
const spansExpired = computed(
    () =>
        summary.value !== null &&
        summary.value.spanCount > 0 &&
        (props.result?.spans.length ?? 0) === 0 &&
        !props.result?.unavailable,
);

/**
 * The detail link carries the trace's own start time.
 *
 * Same reason it does everywhere else: with `ts` the span query on the other
 * side is bounded to minutes, without it ClickHouse hunts a trace id through
 * the whole retention window.
 */
const detailHref = computed(() =>
    traceHref(props.teamSlug, props.traceId, {
        ts: summary.value?.startedAt ?? null,
    }),
);

/**
 * A row in the preview is a door to that row on the page.
 *
 * The compact waterfall has no detail panel of its own, so selecting a span
 * here can only mean "show me this one properly": the full page opens with
 * `?span=` set, lands on that row, and carries the trace's start time so the
 * span query on the other side stays bounded.
 */
function openSpan(spanId: string) {
    router.visit(
        traceHref(props.teamSlug, props.traceId, {
            ts: summary.value?.startedAt ?? null,
            span: spanId,
        }),
    );
}
</script>

<template>
    <!--
      An in-flow column, not an overlay. The reader is still in the log stream
      and has not navigated anywhere: the stream narrows, stays scrollable, and
      every row stays clickable so the panel can be swapped from row to row
      without closing it. An overlay with a focus trap would make this a modal
      with extra steps.
    -->
    <aside
        class="flex min-h-0 w-full shrink-0 flex-col overflow-hidden rounded-lg border bg-card lg:w-[28rem]"
        data-test="trace-panel"
        aria-label="Trace preview"
    >
        <header class="flex items-start gap-2 border-b px-3 py-2">
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <div class="flex min-w-0 items-center gap-2">
                    <span
                        :class="
                            cn(
                                'size-1.5 shrink-0 rounded-full',
                                summary === null
                                    ? 'bg-muted-foreground/40'
                                    : failedTrace
                                      ? SPAN_STATUS_BAR_CLASS.Error
                                      : SPAN_STATUS_BAR_CLASS.Ok,
                            )
                        "
                        aria-hidden="true"
                    />
                    <h2 class="min-w-0 truncate text-sm font-semibold">
                        {{ summary?.rootName || 'Trace' }}
                    </h2>
                </div>
                <p class="truncate font-mono text-xs text-muted-foreground">
                    {{ traceId }}
                </p>
            </div>

            <button
                type="button"
                class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                aria-label="Close trace preview"
                data-test="trace-panel-close"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </header>

        <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3">
            <!-- Loading. -->
            <template v-if="!result && !failed">
                <div class="flex gap-4">
                    <Skeleton class="h-8 w-20" />
                    <Skeleton class="h-8 w-20" />
                    <Skeleton class="h-8 w-20" />
                </div>
                <Skeleton class="h-40 w-full" />
            </template>

            <p
                v-else-if="failed"
                class="text-sm text-muted-foreground"
                data-test="trace-panel-failed"
            >
                The trace could not be loaded. Check the connection and try the
                row again.
            </p>

            <p
                v-else-if="result?.unavailable"
                class="text-sm text-muted-foreground"
                data-test="trace-panel-unavailable"
            >
                Trace storage is busy and could not answer in time. Nothing is
                lost — retry in a moment.
            </p>

            <p
                v-else-if="summary === null"
                class="text-sm text-muted-foreground"
                data-test="trace-panel-not-found"
            >
                No trace with this id is stored for your projects. Trace
                summaries are kept for 90 days.
            </p>

            <template v-else>
                <dl class="grid grid-cols-3 gap-x-4 gap-y-2">
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <dt class="text-xs text-muted-foreground">Duration</dt>
                        <dd class="text-sm tabular-nums">
                            {{ formatDuration(summary.durationMs) }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <dt class="text-xs text-muted-foreground">Spans</dt>
                        <dd class="text-sm tabular-nums">
                            {{ summary.spanCount.toLocaleString() }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <dt class="text-xs text-muted-foreground">Errors</dt>
                        <dd
                            :class="
                                cn(
                                    'text-sm tabular-nums',
                                    failedTrace
                                        ? SPAN_STATUS_TEXT_CLASS.Error
                                        : 'text-muted-foreground',
                                )
                            "
                        >
                            {{ summary.errorCount.toLocaleString() }}
                        </dd>
                    </div>
                    <div class="col-span-2 flex min-w-0 flex-col gap-0.5">
                        <dt class="text-xs text-muted-foreground">Service</dt>
                        <dd class="truncate text-sm">
                            {{ summary.rootService || '—' }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <dt class="text-xs text-muted-foreground">Started</dt>
                        <dd
                            class="truncate text-sm tabular-nums"
                            :title="formatUtcTimestamp(summary.startedAt)"
                        >
                            {{ formatTimestamp(summary.startedAt) }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="spansExpired"
                    class="text-sm text-muted-foreground"
                    data-test="trace-panel-spans-expired"
                >
                    This trace's spans have passed the 30-day retention window,
                    so the waterfall cannot be drawn. The summary above is kept
                    for 90 days.
                </p>

                <template v-else>
                    <p
                        v-if="result?.truncated"
                        class="text-xs text-muted-foreground"
                        data-test="trace-panel-truncated"
                    >
                        Showing the first spans of
                        {{ summary.spanCount.toLocaleString() }}. Open the
                        detail for the whole trace.
                    </p>

                    <div
                        class="flex min-h-40 flex-col overflow-hidden rounded-md border"
                    >
                        <SpanWaterfall
                            :spans="result?.spans ?? []"
                            :selected-span-id="null"
                            compact
                            @select="openSpan"
                        />
                    </div>
                </template>
            </template>
        </div>

        <!--
          The panel is a peek; the page is an investigation. This is the door
          between them, so it stays put at the bottom rather than scrolling away
          with the spans — and it works even when the spans are gone.
        -->
        <footer class="border-t p-3">
            <Button
                as-child
                variant="outline"
                size="sm"
                class="w-full"
                data-test="trace-panel-detail"
            >
                <Link :href="detailHref">
                    Go to detail
                    <ArrowUpRight class="size-4" />
                </Link>
            </Button>
        </footer>
    </aside>
</template>
