<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Copy, ExternalLink, ScrollText } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SpanDetailPanel from '@/components/SpanDetailPanel.vue';
import SpanSearch from '@/components/SpanSearch.vue';
import SpanWaterfall from '@/components/SpanWaterfall.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import { Button } from '@/components/ui/button';
import { formatTimestamp, formatUtcTimestamp } from '@/lib/logs';
import {
    durationClass,
    formatDuration,
    parentLink,
    serviceColours,
    SPAN_STATUS_BAR_CLASS,
    SPAN_STATUS_TEXT_CLASS,
    traceHref,
} from '@/lib/traces';
import { cn } from '@/lib/utils';
import { index as logsIndex } from '@/routes/logs';
import { index as tracesIndex } from '@/routes/traces';
import type {
    LinkedTrace,
    Span,
    Team,
    TraceLogs,
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
    /**
     * How many log lines this trace wrote, and the window they were counted
     * in. Null when there is no summary and so no window to count in.
     */
    logs: TraceLogs | null;
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

/**
 * The span named in the URL, if it is one this page holds.
 *
 * `?span=` is the deep link into a waterfall — from a log line, from another
 * trace's span link, from a colleague. Read from the location rather than
 * from a prop so the page needs nothing from the server it does not already
 * have, and ignored when the id is not among the spans: a link to a row that
 * aged out or fell past the cap opens the trace normally rather than nothing.
 */
function spanFromUrl(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const span = new URLSearchParams(window.location.search).get('span');

    return span && props.spans.some((s) => s.spanId === span) ? span : null;
}

const selectedSpanId = ref<string | null>(spanFromUrl());

/**
 * The span the waterfall must scroll to and expand for. Set on load from the
 * URL and whenever the search steps onto a match — never on a plain click,
 * which selects a row that is already on screen.
 */
const revealSpanId = ref<string | null>(selectedSpanId.value);

/** The current search's matches; `null` while nothing is being searched. */
const matches = ref<Set<string> | null>(null);

/**
 * Keep `?span=` in step with the selection without a round trip.
 *
 * A client-side replace: the props are unchanged, so refetching them would be
 * a wasted query against ClickHouse, and pushing a history entry per click
 * would make the back button walk every row the reader ever looked at. Every
 * other parameter — `ts` above all — is preserved.
 */
function syncUrl(spanId: string | null) {
    if (typeof window === 'undefined') {
        return;
    }

    const params = new URLSearchParams(window.location.search);

    if (spanId) {
        params.set('span', spanId);
    } else {
        params.delete('span');
    }

    const query = params.toString();
    const url = `${window.location.pathname}${query ? `?${query}` : ''}`;

    if (url === `${window.location.pathname}${window.location.search}`) {
        return;
    }

    router.replace({ url, preserveScroll: true, preserveState: true });
}

function select(spanId: string, options: { reveal?: boolean } = {}) {
    selectedSpanId.value = spanId;

    if (options.reveal) {
        revealSpanId.value = spanId;
    }

    syncUrl(spanId);
}

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
            ? traceHref(teamSlug.value, link.traceId, {
                  ts: trace.startedAt,
                  span: link.spanId,
              })
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

/**
 * How many services the drawn spans touch. Read from the spans this page holds,
 * so a trace cut at the span cap says "at least".
 */
const serviceCount = computed(
    () =>
        new Set(props.spans.map((span) => span.serviceName || 'unknown')).size,
);

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });

const heading = computed(() =>
    props.summary === null
        ? 'Trace'
        : props.summary.rootName || 'Root span not received',
);

/**
 * The other half of the logs/traces link, from the trace side.
 *
 * Opens the log viewer on exactly this trace id inside the window the count
 * was taken in, so the number beside the link is the number the click shows.
 * The link stands even when the count could not be taken — "unavailable" hides
 * the figure, not the way through.
 */
const logsHref = computed(() =>
    props.logs
        ? logsIndex(teamSlug.value, {
              query: {
                  trace_id: props.traceId,
                  from: props.logs.from,
                  to: props.logs.to,
              },
          }).url
        : null,
);

const logsValue = computed(() => {
    const count = props.logs?.count ?? null;

    if (count === null) {
        return 'View logs';
    }

    return `${count.toLocaleString()} ${count === 1 ? 'log' : 'logs'}`;
});
</script>

<template>
    <Head :title="`Trace ${traceId.slice(0, 12)}`" />

    <div class="flex min-h-0 flex-1 flex-col">
        <!--
          The header answers "what am I looking at, and did it work?" before the
          waterfall is read at all: the root operation as the title, where it
          ran as one sentence, and the numbers as a strip below.
        -->
        <PageHeader :title="heading">
            <template #title>
                <span
                    :class="
                        cn(
                            'break-words',
                            summary !== null &&
                                !summary.rootName &&
                                'text-muted-foreground',
                        )
                    "
                    :title="heading"
                    >{{ heading }}</span
                >
            </template>

            <p
                v-if="summary"
                class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
                data-test="trace-context"
            >
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
                        :class="
                            failed
                                ? cn(
                                      'font-medium',
                                      SPAN_STATUS_TEXT_CLASS.Error,
                                  )
                                : 'text-foreground'
                        "
                        >{{ failed ? 'error' : 'ok' }}</span
                    >
                </span>
                <span class="text-input" aria-hidden="true">·</span>
                <span class="font-mono text-foreground">{{
                    summary.rootService || '—'
                }}</span>
                <span v-if="serviceVersion" class="font-mono">{{
                    serviceVersion
                }}</span>
                <template v-if="environment">
                    <span class="text-input" aria-hidden="true">·</span>
                    <span>{{ environment }}</span>
                </template>
                <span class="text-input" aria-hidden="true">·</span>
                <span
                    class="font-mono"
                    :title="formatUtcTimestamp(summary.startedAt)"
                    >{{ formatTimestamp(summary.startedAt) }}</span
                >
                <template v-if="sdk">
                    <span class="text-input" aria-hidden="true">·</span>
                    <span>{{ sdk }}</span>
                </template>
            </p>
            <p v-else class="font-mono">{{ traceId }}</p>

            <template #actions>
                <Button
                    v-if="summary"
                    type="button"
                    variant="ghost"
                    class="font-mono text-muted-foreground"
                    :title="traceId"
                    :aria-label="copied ? 'Copied' : 'Copy Trace ID'"
                    data-test="trace-copy-id"
                    @click="copy(traceId)"
                >
                    {{ traceId.slice(0, 16) }}…
                    <component :is="copied ? Check : Copy" class="size-3.5" />
                </Button>
                <!--
                  "0 logs" still renders — a trace that wrote nothing is a fact
                  worth a glance — and the count is the label, because it is the
                  reason to click.
                -->
                <Button v-if="logs && logsHref" variant="outline" as-child>
                    <Link
                        :href="logsHref"
                        data-test="trace-logs-link"
                        :title="
                            logs.count === null
                                ? 'Count unavailable'
                                : undefined
                        "
                    >
                        <ScrollText class="size-4" />
                        {{ logsValue }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <!--
          The numbers, on the surface: one strip, hairlines between, the figure
          large and its context small — the dashboard's KPI strip. Duration
          takes its magnitude, errors take severity; the rest stay ink.
        -->
        <div v-if="summary" class="px-4 pb-6 md:px-10">
            <dl
                class="grid grid-cols-2 border-y sm:grid-cols-4"
                data-test="trace-summary"
            >
                <div class="flex flex-col gap-1.5 py-5 pr-6">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Duration
                    </dt>
                    <dd
                        :class="
                            cn(
                                'font-mono text-3xl leading-9 font-semibold tracking-tight tabular-nums',
                                durationClass(summary.durationMs),
                            )
                        "
                    >
                        {{ formatDuration(summary.durationMs) }}
                    </dd>
                </div>

                <div class="flex flex-col gap-1.5 border-l py-5 pr-6 pl-6">
                    <dt class="text-xs font-medium text-muted-foreground">
                        Spans
                    </dt>
                    <dd
                        class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                    >
                        {{ summary.spanCount.toLocaleString() }}
                    </dd>
                </div>

                <div
                    class="flex flex-col gap-1.5 border-t py-5 pr-6 sm:border-t-0 sm:border-l sm:pl-6"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        Services
                    </dt>
                    <dd
                        class="text-3xl leading-9 font-semibold tracking-tight tabular-nums"
                    >
                        {{ spans.length > 0 ? serviceCount : '—' }}
                    </dd>
                </div>

                <div
                    class="flex flex-col gap-1.5 border-t border-l py-5 pr-6 pl-6 sm:border-t-0"
                >
                    <dt class="text-xs font-medium text-muted-foreground">
                        Errors
                    </dt>
                    <dd
                        :class="
                            cn(
                                'text-3xl leading-9 font-semibold tracking-tight tabular-nums',
                                failed && SPAN_STATUS_TEXT_CLASS.Error,
                            )
                        "
                    >
                        {{ summary.errorCount.toLocaleString() }}
                    </dd>
                </div>
            </dl>
        </div>

        <!--
          Why this trace starts where it does. A root span that names a parent
          through a link is not really a root — the parent is in another trace —
          and saying so is the difference between "a span from nowhere" and "the
          rest of this is somewhere else".
        -->
        <div v-if="inboundLink" class="px-4 pb-5 md:px-10">
            <component
                :is="inboundLink.href ? Link : 'div'"
                v-bind="inboundLink.href ? { href: inboundLink.href } : {}"
                :class="
                    cn(
                        'flex items-start gap-2 rounded-lg border px-4 py-3 text-sm text-muted-foreground',
                        inboundLink.href &&
                            'transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none',
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
        </div>

        <StorageBusy
            v-if="unavailable"
            store="Trace storage"
            class="border-t"
            data-test="trace-unavailable"
        />

        <div
            v-else-if="summary === null"
            class="flex flex-col gap-2 border-t px-4 py-8 md:px-10"
            data-test="trace-not-found"
        >
            <p class="text-sm font-medium text-foreground">No such trace</p>
            <p class="max-w-prose text-sm text-muted-foreground">
                No trace with this id is stored for your projects. Trace
                summaries are kept for 90 days.
            </p>
        </div>

        <p
            v-else-if="spansExpired"
            class="border-t px-4 py-8 text-sm text-muted-foreground md:px-10"
            data-test="trace-spans-expired"
        >
            This trace's spans have passed the 30-day retention window, so the
            waterfall cannot be drawn. The summary above is kept for 90 days.
        </p>

        <!--
          The waterfall sits on the surface, on the page gutter; the span
          detail is an in-flow column beside it, separated by a hairline.
        -->
        <div
            v-else
            class="grid min-h-0 flex-1 border-t lg:grid-cols-[minmax(0,1fr)_24rem]"
        >
            <section
                class="flex min-h-0 flex-col overflow-hidden"
                data-test="trace-waterfall"
            >
                <p
                    v-if="truncated"
                    class="border-b px-4 py-2.5 text-xs text-muted-foreground md:pl-10"
                    data-test="trace-truncated"
                >
                    Showing the first {{ spanLimit.toLocaleString() }} spans of
                    {{ summary?.spanCount.toLocaleString() }}. Spans whose
                    parent fell outside this page are drawn at the top level.
                </p>

                <!--
                  Search sits above the waterfall, not in the page header: it
                  acts on these rows and nothing else. Matches dim the rest
                  rather than filtering, so no bar moves while the reader types.
                -->
                <div class="border-b px-4 py-3 md:pl-10">
                    <SpanSearch
                        :spans="spans"
                        :selected-span-id="selectedSpan?.spanId ?? null"
                        @matches="matches = $event"
                        @step="select($event, { reveal: true })"
                    />
                </div>

                <SpanWaterfall
                    :spans="spans"
                    :selected-span-id="selectedSpan?.spanId ?? null"
                    :reveal-span-id="revealSpanId"
                    :matches="matches"
                    gutter
                    @select="select($event)"
                />
            </section>

            <SpanDetailPanel
                v-if="selectedSpan"
                :span="selectedSpan"
                :team-slug="teamSlug"
                :colour="colours.get(selectedSpan.serviceName || 'unknown')"
                :linked-traces="linkedTraces"
                class="border-t lg:border-t-0 lg:border-l"
            />
        </div>
    </div>
</template>
