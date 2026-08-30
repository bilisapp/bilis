<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import SpanDetailPanel from '@/components/SpanDetailPanel.vue';
import SpanWaterfall from '@/components/SpanWaterfall.vue';
import TraceFact from '@/components/TraceFact.vue';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import {
    formatDuration,
    parentLink,
    serviceColours,
    SPAN_STATUS_BAR_CLASS,
    SPAN_STATUS_TEXT_CLASS,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as tracesIndex, show as traceShow } from '@/routes/traces';
import type {
    LinkedTrace,
    Span,
    Team,
    TraceResource,
    TraceSummary,
} from '@/types';

const props = defineProps<{
    traceId: string;
    /** Null when nothing at all is stored for this id. */
    summary: TraceSummary | null;
    /** Read once from the root span, not repeated on every row. */
    resource: TraceResource | null;
    /** Already flattened depth-first by SpanTree, orphans at root level. */
    spans: Span[];
    /**
     * The traces this one's spans link to that are actually stored here, keyed
     * by trace id. A link names a trace; this says which of them can be opened.
     */
    linkedTraces: Record<string, LinkedTrace>;
    /** The trace has more spans than the cap allows. */
    truncated: boolean;
    unavailable: boolean;
    spanLimit: number;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Traces',
                href: layoutProps.currentTeam
                    ? tracesIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            { title: 'Trace' },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const selectedSpanId = ref<string | null>(null);

const selectedSpan = computed(
    () =>
        props.spans.find((span) => span.spanId === selectedSpanId.value) ??
        props.spans[0] ??
        null,
);

const colours = computed(() => serviceColours(props.spans));

/**
 * Where this trace came from, when it says so with a link rather than a parent.
 *
 * A span whose parent lives in another trace cannot point at it through the
 * tree, so the exporter records a `parent_of` link instead — Claude Code does
 * this on every `llm_request`. Read from the trace's own root, because that is
 * the span whose missing parent explains the whole trace: without this the page
 * shows a bar from nowhere and has no way to say otherwise.
 */
const inboundLink = computed(() => {
    const root = props.spans.find((span) => span.depth === 0);

    if (!root) {
        return null;
    }

    const link = parentLink(root);

    if (!link || link.traceId === props.traceId) {
        return null;
    }

    const trace = props.linkedTraces[link.traceId] ?? null;

    return {
        link,
        trace,
        href: trace
            ? traceShow({
                  current_team: teamSlug.value,
                  trace: link.traceId,
              }).url.concat(`?ts=${encodeURIComponent(trace.startedAt)}`)
            : null,
    };
});

/**
 * The trace's own status, from its spans rather than from a status column.
 *
 * A trace is failed if anything in it failed — which is what the summary's
 * error count already says, and what a reader means when they ask whether the
 * request worked.
 */
const failed = computed(() => (props.summary?.errorCount ?? 0) > 0);

/**
 * Facts drawn from the root span's resource, when it carried them.
 *
 * These are OpenTelemetry's own conventions, so they are read rather than
 * invented — a trace that never set them simply shows fewer facts instead of
 * showing empty ones.
 */
const environment = computed(
    () =>
        props.resource?.attributes['deployment.environment.name'] ??
        props.resource?.attributes['deployment.environment'] ??
        null,
);

const serviceVersion = computed(
    () => props.resource?.attributes['service.version'] ?? null,
);

const sdk = computed(() => {
    const name =
        props.resource?.attributes['telemetry.sdk.name'] ??
        props.resource?.scopeName ??
        null;

    if (name === null || name === '') {
        return null;
    }

    const version =
        props.resource?.attributes['telemetry.sdk.version'] ??
        props.resource?.scopeVersion ??
        '';

    return version === '' ? name : `${name} ${version}`;
});

/**
 * The summary outlived its spans.
 *
 * Summaries are kept 90 days and spans 30, so a trace can legitimately be known
 * and unopenable. That is worth saying plainly rather than showing an empty
 * waterfall that reads as a bug.
 */
const spansExpired = computed(
    () =>
        props.summary !== null &&
        props.summary.spanCount > 0 &&
        props.spans.length === 0 &&
        !props.unavailable,
);

const heading = computed(
    () => props.summary?.rootName || 'Root span not received',
);
</script>

<template>
    <Head :title="`Trace ${traceId.slice(0, 12)}`" />

    <div class="flex min-h-0 flex-1 flex-col gap-4 p-4">
        <!--
          The header answers "what am I looking at, and did it work?" before the
          waterfall is read at all. It is a grid of quiet labels over loud
          values rather than a row of boxes: at this density the type contrast
          separates the facts, and boxes would only add lines to count.
        -->
        <header class="flex flex-col gap-4 rounded-lg border bg-card p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-xl font-semibold break-words">
                    {{ heading }}
                </h1>
                <p
                    v-if="summary"
                    class="text-xs text-muted-foreground"
                    :title="formatUtcTimestamp(summary.startedAt)"
                >
                    {{ formatTimestamp(summary.startedAt) }}
                </p>
            </div>

            <dl
                v-if="summary"
                class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4"
                data-test="trace-summary"
            >
                <TraceFact label="Status" :value="failed ? 'error' : 'ok'">
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            :class="
                                cn(
                                    'size-1.5 rounded-full',
                                    failed
                                        ? SPAN_STATUS_BAR_CLASS.Error
                                        : SPAN_STATUS_BAR_CLASS.Ok,
                                )
                            "
                            aria-hidden="true"
                        />
                        <span
                            :class="failed ? SPAN_STATUS_TEXT_CLASS.Error : ''"
                        >
                            {{ failed ? 'error' : 'ok' }}
                        </span>
                    </span>
                </TraceFact>

                <TraceFact
                    label="Duration"
                    :value="formatDuration(summary.durationMs)"
                />

                <TraceFact
                    label="Spans"
                    :value="String(summary.spanCount)"
                    :detail="
                        summary.errorCount > 0
                            ? `${summary.errorCount} error${summary.errorCount === 1 ? '' : 's'}`
                            : undefined
                    "
                />

                <TraceFact
                    label="Service"
                    :value="summary.rootService || '—'"
                    :detail="serviceVersion ?? undefined"
                />

                <TraceFact
                    label="Started"
                    :value="formatTimestamp(summary.startedAt)"
                />

                <TraceFact
                    v-if="environment"
                    label="Environment"
                    :value="environment"
                />

                <TraceFact v-if="sdk" label="Instrumentation" :value="sdk" />

                <TraceFact
                    label="Trace ID"
                    :value="traceId"
                    mono
                    copyable
                    class="col-span-2"
                />
            </dl>
        </header>

        <!--
          Why this trace starts where it does. A root span that names a parent
          through a link is not really a root — the parent is in another trace —
          and saying so is the difference between "a span from nowhere" and "the
          rest of this is somewhere else".
        -->
        <component
            :is="inboundLink?.href ? Link : 'div'"
            v-if="inboundLink"
            v-bind="inboundLink.href ? { href: inboundLink.href } : {}"
            :class="
                cn(
                    'flex items-start gap-2 rounded-lg border bg-card px-4 py-3 text-xs text-muted-foreground',
                    inboundLink.href &&
                        'transition-colors hover:bg-accent/60 focus-visible:bg-accent/60 focus-visible:outline-none',
                )
            "
            data-test="trace-inbound-link"
        >
            <span class="min-w-0 flex-1">
                <template v-if="inboundLink.trace">
                    Continues
                    <span class="font-medium text-foreground">
                        {{
                            inboundLink.trace.rootName ||
                            'a trace with no root span'
                        }}
                    </span>
                    — this trace's root links to it as its parent.
                </template>
                <template v-else>
                    This trace's root names a parent span in trace
                    <span class="font-mono break-all">
                        {{ inboundLink.link.traceId }}
                    </span>
                    , which is not stored here — it was never sent to this
                    instance, or its spans have aged out. That is why the
                    waterfall starts where it does.
                </template>
            </span>

            <ExternalLink
                v-if="inboundLink.href"
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
        </component>

        <p
            v-if="unavailable"
            class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
            data-test="trace-unavailable"
        >
            Trace storage is busy and could not answer in time. Nothing is lost
            — retry in a moment.
        </p>

        <p
            v-else-if="summary === null"
            class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
            data-test="trace-not-found"
        >
            No trace with this id is stored for your projects. Trace summaries
            are kept for 90 days.
        </p>

        <p
            v-else-if="spansExpired"
            class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
            data-test="trace-spans-expired"
        >
            This trace's spans have passed the 30-day retention window, so the
            waterfall cannot be drawn. The summary above is kept for 90 days.
        </p>

        <div
            v-else
            class="grid min-h-0 flex-1 gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <section
                class="flex min-h-0 flex-col overflow-hidden rounded-lg border bg-card"
                data-test="trace-waterfall"
            >
                <p
                    v-if="truncated"
                    class="border-b px-3 py-2 text-xs text-muted-foreground"
                    data-test="trace-truncated"
                >
                    Showing the first {{ spanLimit.toLocaleString() }} spans of
                    {{ summary?.spanCount.toLocaleString() }}. Spans whose
                    parent fell outside this page are drawn at the top level.
                </p>

                <SpanWaterfall
                    :spans="spans"
                    :selected-span-id="selectedSpan?.spanId ?? null"
                    @select="selectedSpanId = $event"
                />
            </section>

            <SpanDetailPanel
                v-if="selectedSpan"
                :span="selectedSpan"
                :team-slug="teamSlug"
                :colour="colours.get(selectedSpan.serviceName || 'unknown')"
                :linked-traces="linkedTraces"
            />
        </div>
    </div>
</template>
