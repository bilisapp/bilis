<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import TraceHistogram from '@/components/TraceHistogram.vue';
import TraceListRow from '@/components/TraceListRow.vue';
import TracesTabs from '@/components/TracesTabs.vue';
import TracesToolbar from '@/components/TracesToolbar.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useListCursor } from '@/composables/useListCursor';
import {
    DEFAULT_RANGE_PRESET,
    presetForRange,
    RANGE_PRESETS,
    timeZoneNotice,
} from '@/lib/logs';
import { traceFilterQuery, traceHref } from '@/lib/traces';
import { index as tracesIndex, tail as tracesTail } from '@/routes/traces';
import type {
    LogProject,
    LogRangePreset,
    Team,
    TraceFilters,
    TraceHistogram as TraceHistogramData,
    TraceResult,
    TraceSummary,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: TraceFilters;
    /** Has this team ever sent a span? A fact about the team, not the window. */
    hasTraces: boolean;
    /** Root services seen lately, for the toolbar's picker; deferred. */
    services?: string[];
    traces?: TraceResult;
    /** Volume and failures over the window; deferred in its own group. */
    histogram?: TraceHistogramData;
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
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const timeZone = timeZoneNotice();

const range = computed<LogRangePreset>(() =>
    presetForRange(props.filters.from, props.filters.to),
);

const canReset = computed(
    () =>
        props.filters.project !== null ||
        props.filters.service !== null ||
        props.filters.errors ||
        props.filters.minDuration !== null ||
        range.value !== DEFAULT_RANGE_PRESET,
);

/**
 * The filters as the tabs and the poll both have to spell them.
 *
 * A function, not a computed: a preset window is relative — "last hour" is the
 * hour ending *now* — so anything that cached the query string at render time
 * would hand the other tab, and every poll, a window frozen at the moment the
 * page loaded. Each caller asks at the moment it needs it.
 */
const query = () => traceFilterQuery(props.filters, range.value);

/**
 * Push a filter change into the URL.
 *
 * The query string is the state: a filtered view is a link someone can send,
 * and the back button walks the filters — the same contract the log viewer has.
 */
function apply(changes: Record<string, string | number | boolean | null>) {
    router.get(
        tracesIndex(teamSlug.value).url,
        traceFilterQuery(props.filters, range.value, changes),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function applyRange(preset: LogRangePreset) {
    const minutes = RANGE_PRESETS.find(
        (option) => option.value === preset,
    )?.minutes;

    if (minutes === undefined) {
        return;
    }

    const to = new Date();
    const from = new Date(to.getTime() - minutes * 60_000);

    apply({ from: from.toISOString(), to: to.toISOString() });
}

function reset() {
    router.get(
        tracesIndex(teamSlug.value).url,
        {},
        { preserveScroll: true, replace: true },
    );
}

/**
 * Clicking a histogram bar narrows the list to that bucket.
 *
 * An absolute window, so the range control reads "custom" and the query string
 * carries the bounds themselves — the same thing the log viewer does.
 */
function onZoom(window: { from: string; to: string }) {
    apply({ from: window.from, to: window.to });
}

/*
 * Live polling.
 *
 * A trace list goes stale the instant it renders: the window's upper bound was
 * fixed when the page loaded, so every trace recorded since is invisible until
 * something asks again. Polling is on by default for that reason — an
 * observability list that quietly stops moving is worse than one that costs a
 * query every few seconds — and the toggle is there for a reader who has found
 * what they were looking for and wants the page to hold still.
 *
 * Five seconds, not the log viewer's two: a trace is a heavier row and an
 * aggregating read, and nobody watches traces scroll the way they watch logs.
 */
const POLL_INTERVAL_MS = 5_000;

/**
 * How many polls may fail in a row before the page stops asking.
 *
 * One failed poll is a blip and the next tick is its retry. Three in a row is
 * a server that cannot be reached, and a Live dot that keeps pulsing over a
 * list that has silently stopped growing is a lie — so Live is switched off
 * and the page says why, with the retry one click away.
 */
const POLL_FAILURE_LIMIT = 3;

const live = ref(true);

const pollFailures = ref(0);

/** Live was switched off by failures, not by the reader. */
const pollPaused = ref(false);

/**
 * Traces the poll has brought in, keyed by id in `rows` below.
 *
 * Kept here rather than merged into the Inertia prop because a poll must not
 * re-render the page: an Inertia visit would replace the list and take the
 * reader's scroll position with it.
 */
const polled = ref<TraceSummary[]>([]);

const freshIds = ref<Set<string>>(new Set());

let freshTimer: ReturnType<typeof setTimeout> | null = null;

const markFresh = (traces: TraceSummary[]) => {
    freshIds.value = new Set(traces.map((trace) => trace.traceId));

    if (freshTimer !== null) {
        clearTimeout(freshTimer);
    }

    freshTimer = setTimeout(() => {
        freshIds.value = new Set();
        freshTimer = null;
    }, 1_500);
};

/**
 * The list, polled rows first and each trace exactly once.
 *
 * Keying by trace id is not tidiness. `trace_summary` holds one row per trace
 * per insert block, so a trace read while its spans are still arriving carries
 * a partial span count; the poll deliberately re-reads the last few seconds and
 * a later, fuller row must replace the earlier one rather than sit beside it.
 */
const rows = computed<TraceSummary[]>(() => {
    const byId = new Map<string, TraceSummary>();

    for (const trace of props.traces?.rows ?? []) {
        byId.set(trace.traceId, trace);
    }

    for (const trace of polled.value) {
        byId.set(trace.traceId, trace);
    }

    return [...byId.values()].sort((a, b) =>
        b.startedAt.localeCompare(a.startedAt),
    );
});

/*
 * ClickHouse timestamps are naive UTC strings, and that is exactly what goes
 * back out as the cursor. Falling back to the window's end keeps the very first
 * poll from asking for the whole retention period.
 */
const newestStart = () => rows.value[0]?.startedAt ?? props.filters.to;

const pollRequest = useHttp<{ after: string }, TraceResult>({ after: '' });

const fetchNewTraces = async () => {
    if (!live.value || pollRequest.processing) {
        return;
    }

    // Nothing is watching a hidden tab, and the rows will be fetched in one go
    // when it comes back.
    if (document.visibilityState === 'hidden') {
        return;
    }

    pollRequest.after = newestStart();

    try {
        const result = await pollRequest.get(
            tracesTail(teamSlug.value, { query: query() }).url,
        );

        pollFailures.value = 0;

        if (result?.rows?.length) {
            const known = new Set(rows.value.map((trace) => trace.traceId));

            polled.value = [...result.rows, ...polled.value].slice(0, 200);
            markFresh(result.rows.filter((trace) => !known.has(trace.traceId)));
        }
    } catch {
        pollFailures.value += 1;

        if (pollFailures.value >= POLL_FAILURE_LIMIT) {
            pollPaused.value = true;
            live.value = false;
        }
    }
};

/** The retry offered by the paused notice: clear the slate and ask again. */
function resumeLive() {
    pollFailures.value = 0;
    pollPaused.value = false;
    live.value = true;
    void fetchNewTraces();
}

let pollTimer: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    if (freshTimer !== null) {
        clearTimeout(freshTimer);
        freshTimer = null;
    }
};

watch(
    live,
    (enabled) => {
        stopPolling();

        if (enabled) {
            // The reader switched Live back on themselves, which is a retry.
            pollFailures.value = 0;
            pollPaused.value = false;

            pollTimer = setInterval(
                () => void fetchNewTraces(),
                POLL_INTERVAL_MS,
            );
        }
    },
    { immediate: true },
);

/*
 * A filter change is a different question, so the rows the old one collected
 * are no longer answers to it — and the row the cursor held is gone with them.
 */
watch(
    () => props.filters,
    () => {
        polled.value = [];
        freshIds.value = new Set();
        resetCursor();
    },
);

onBeforeUnmount(stopPolling);

/**
 * `less`-style keys over the list, the same model the log stream uses. "Open"
 * here means following the row's link; `/` lands in the service filter. Every
 * one of them has a pointer equivalent — this is a faster route, never the
 * only one.
 */
const { cursor, reset: resetCursor } = useListCursor({
    count: () => rows.value.length,
    open: (index) => {
        const trace = rows.value[index];

        if (!trace || trace.spansExpired) {
            return;
        }

        router.visit(
            traceHref(teamSlug.value, trace.traceId, { ts: trace.startedAt }),
        );
    },
    focusSearch: () => {
        const field = document.querySelector<HTMLInputElement>(
            "[data-test='traces-service']",
        );

        field?.focus();
        field?.select();
    },
});

/**
 * Keep the cursor row on screen when it moves off the top or bottom edge.
 */
watch(cursor, (index) => {
    if (index === null) {
        return;
    }

    void nextTick(() => {
        document
            .querySelector(`[data-trace-index='${index}']`)
            ?.scrollIntoView({ block: 'nearest' });
    });
});
</script>

<template>
    <Head title="Traces" />

    <!--
      The page column scrolls, not the trace list: letting the section take its
      natural height is what a list page wants, and it keeps the toolbar and the
      tabs in one flow rather than pinning them against a nested scroller.
    -->
    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
        <TracesTabs :team-slug="teamSlug" :query="query" />

        <TracesToolbar
            :projects="projects"
            :project="filters.project"
            :services="services"
            :service="filters.service"
            :errors="filters.errors"
            :min-duration="filters.minDuration"
            :range="range"
            :can-reset="canReset"
            :live="live"
            :polling="pollRequest.processing"
            @update:project="apply({ project: $event })"
            @update:service="apply({ service: $event })"
            @update:errors="apply({ errors: $event })"
            @update:min-duration="apply({ min_duration: $event })"
            @update:range="applyRange"
            @update:live="live = $event"
            @reset="reset"
        />

        <TraceHistogram
            v-if="hasTraces"
            :histogram="histogram"
            @zoom="onZoom"
        />

        <!--
          Said once, outside the list, so it survives whatever the list is
          showing. Three failed polls in a row switched Live off; the reader
          is told rather than left watching a dot that stopped meaning anything.
        -->
        <div
            v-if="pollPaused"
            class="flex items-center justify-between gap-3 rounded-lg border bg-card px-4 py-2 text-xs text-muted-foreground"
            role="status"
            data-test="traces-live-paused"
        >
            <span>Live paused — could not reach the server.</span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="traces-live-retry"
                @click="resumeLive"
            >
                Retry
            </Button>
        </div>

        <section class="flex flex-col rounded-lg border bg-card">
            <header
                class="flex items-baseline justify-between gap-2 border-b px-4 py-3"
            >
                <h2 class="text-sm font-semibold">Traces</h2>
                <p class="text-xs text-muted-foreground">{{ timeZone }}</p>
            </header>

            <div v-if="!traces" class="flex flex-col gap-2 p-4">
                <Skeleton v-for="index in 6" :key="index" class="h-10 w-full" />
            </div>

            <p
                v-else-if="traces.unavailable"
                class="p-6 text-sm text-muted-foreground"
                data-test="traces-unavailable"
            >
                Trace storage is busy and could not answer in time. Nothing is
                lost — retry in a moment.
            </p>

            <!--
              Three empty states, and they mean different things. Never having
              sent a span is a setup problem; an empty window is not. The first
              is only shown while it is still true: a team whose first trace
              arrives through the poll gets the list, not the setup text.
            -->
            <div
                v-else-if="!hasTraces && rows.length === 0"
                class="flex flex-col gap-2 p-6"
                data-test="traces-empty-never"
            >
                <p class="text-sm font-medium">No traces yet</p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Point an OpenTelemetry exporter at
                    <code class="font-mono">/api/v1/traces</code> over OTLP/HTTP
                    with a project API key. Note that gRPC on port 4317 is not
                    supported — collectors default to it.
                </p>
            </div>

            <p
                v-else-if="rows.length === 0"
                class="p-6 text-sm text-muted-foreground"
                data-test="traces-empty-window"
            >
                No traces in this window.
                <span v-if="live">Watching for new ones.</span>
            </p>

            <div v-else>
                <TraceListRow
                    v-for="(trace, index) in rows"
                    :key="trace.traceId"
                    :trace="trace"
                    :team-slug="teamSlug"
                    :data-trace-index="index"
                    :expired="trace.spansExpired"
                    :fresh="freshIds.has(trace.traceId)"
                    :cursor="cursor === index"
                />
            </div>
        </section>
    </div>
</template>
