<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MetricChart from '@/components/MetricChart.vue';
import MetricsTabs from '@/components/MetricsTabs.vue';
import MetricsToolbar from '@/components/MetricsToolbar.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useMetricsLive } from '@/composables/useMetricsLive';
import {
    DEFAULT_RANGE_PRESET,
    presetForRange,
    RANGE_PRESETS,
} from '@/lib/logs';
import {
    DEFAULT_METRIC_AGGREGATION,
    METRIC_TYPE_LABEL,
    metricFilterQuery,
    metricReading,
    metricWindow,
    suggestedMetrics,
} from '@/lib/metrics';
import type { MetricQueryChanges } from '@/lib/metrics';
import { show as docsShow } from '@/routes/docs';
import { index as metricsIndex } from '@/routes/metrics';
import type {
    LogProject,
    LogRangePreset,
    MetricAggregation,
    MetricAttributes,
    MetricCatalog,
    MetricFilters,
    MetricSeriesResult,
    Team,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: MetricFilters;
    /** Has this team ever sent a data point? A fact about the team, not the window. */
    hasMetrics: boolean;
    /** Which metrics exist; deferred. */
    catalog?: MetricCatalog;
    /** The selected metric's attribute keys and top values; deferred. */
    attributes?: MetricAttributes;
    /** The chart itself; deferred in a group of its own. */
    series?: MetricSeriesResult;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Metrics',
                href: layoutProps.currentTeam
                    ? metricsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const range = computed<LogRangePreset>(() =>
    presetForRange(props.filters.from, props.filters.to),
);

const canReset = computed(
    () =>
        props.filters.project !== null ||
        props.filters.service !== null ||
        props.filters.metric !== null ||
        props.filters.groupBy !== null ||
        Object.keys(props.filters.where).length > 0 ||
        props.filters.agg !== DEFAULT_METRIC_AGGREGATION ||
        range.value !== DEFAULT_RANGE_PRESET,
);

const selectedEntry = computed(
    () =>
        props.catalog?.metrics.find(
            (entry) => entry.name === props.filters.metric,
        ) ?? null,
);

/**
 * Live re-reads the same relative window against the clock: the chart when a
 * metric is picked, else the catalog the suggestions come from.
 */
const { live, refreshing } = useMetricsLive({
    available: () => range.value !== 'custom',
    intervalSeconds: () => props.series?.intervalSeconds ?? 60,
    refresh: (options) =>
        router.get(
            metricsIndex(teamSlug.value).url,
            metricFilterQuery(props.filters, range.value, {}),
            {
                ...options,
                only: props.filters.metric
                    ? ['filters', 'series']
                    : ['filters', 'catalog'],
            },
        ),
});

/**
 * The query string is the state: a chart is a link someone can send, and the
 * back button walks the filters.
 */
function apply(changes: MetricQueryChanges) {
    router.get(
        metricsIndex(teamSlug.value).url,
        metricFilterQuery(props.filters, range.value, changes),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

/**
 * Switching metric drops what belonged to the old one. Attribute keys and an
 * aggregation are properties of a metric — `http.route` means nothing on
 * `process.memory.usage` — so carrying them across would chart an empty
 * filter and call it "no data".
 */
function selectMetric(metric: string | null) {
    apply({ metric, where: {}, group_by: null, agg: null });
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
        metricsIndex(teamSlug.value).url,
        {},
        { preserveScroll: true, replace: true },
    );
}

/** The ingest host, taken from where the reader actually is. */
const origin = computed(() =>
    typeof window === 'undefined' ? '' : window.location.origin,
);

/*
 * The header value is url-encoded (`%20`), as the OTLP spec asks and the
 * Collector and Go SDK expect; the docs page covers the SDKs that do not.
 */
const exporterSnippet = computed(
    () => `OTEL_EXPORTER_OTLP_METRICS_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=${origin.value}/api/v1/metrics
OTEL_EXPORTER_OTLP_METRICS_HEADERS=Authorization=Bearer%20<YOUR_API_KEY>`,
);

const metricsDocsHref = docsShow({ section: 'ingestion', page: 'metrics' }).url;

/** The busiest metrics of each source, offered when none is picked. */
const suggestions = computed(() =>
    suggestedMetrics(props.catalog?.metrics ?? []),
);

/** What the Hosts tab inherits: the window and the project. */
const sharedQuery = () => {
    const query: Record<string, string> = metricWindow(
        props.filters,
        range.value,
    );

    if (props.filters.project) {
        query.project = props.filters.project;
    }

    return query;
};

const catalogEmpty = computed(
    () =>
        props.catalog !== undefined &&
        !props.catalog.unavailable &&
        props.catalog.metrics.length === 0,
);
</script>

<template>
    <Head title="Metrics" />

    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold tracking-tight">Metrics</h1>
            <p class="text-sm text-muted-foreground">
                One metric over the window, filtered and split by its
                attributes. The URL is the chart — copy it to share it.
            </p>
        </div>

        <MetricsTabs :team-slug="teamSlug" :query="sharedQuery" />

        <MetricsToolbar
            :projects="projects"
            :project="filters.project"
            :catalog="catalog"
            :metric="filters.metric"
            :service="filters.service"
            :attributes="attributes"
            :where="filters.where"
            :group-by="filters.groupBy"
            :agg="filters.agg"
            :kind="series?.kind"
            :range="range"
            :can-reset="canReset"
            :live="live"
            :live-available="range !== 'custom'"
            :refreshing="refreshing"
            @update:live="live = $event"
            @update:project="apply({ project: $event })"
            @update:metric="selectMetric"
            @update:service="apply({ service: $event })"
            @update:where="apply({ where: $event })"
            @update:group-by="apply({ group_by: $event })"
            @update:agg="(value: MetricAggregation) => apply({ agg: value })"
            @update:range="applyRange"
            @reset="reset"
        />

        <!--
          Three empty states, and they mean different things. Never having
          sent a data point is a setup problem and gets the setup text; an
          empty window is not, and says so plainly.
        -->
        <div
            v-if="!hasMetrics"
            class="flex flex-col gap-3 rounded-lg border bg-card p-6"
            data-test="metrics-empty-never"
        >
            <p class="text-sm font-medium">No metrics yet</p>
            <p class="max-w-prose text-sm text-muted-foreground">
                Point an OpenTelemetry metrics exporter at
                <code class="font-mono">/api/v1/metrics</code> over OTLP/HTTP
                with a project API key. gRPC on port 4317 is not supported —
                SDKs and the Collector default to it, so set the protocol
                explicitly.
            </p>
            <pre
                class="overflow-x-auto rounded-md border bg-muted/40 p-3 font-mono text-xs"
                data-test="metrics-empty-snippet"
            ><code>{{ exporterSnippet }}</code></pre>
            <p class="text-sm text-muted-foreground">
                <a
                    :href="metricsDocsHref"
                    class="font-medium text-foreground underline underline-offset-4"
                >
                    Sending metrics
                </a>
                covers temporality, units and the SDKs that do not url-decode
                the header.
            </p>
        </div>

        <p
            v-else-if="catalog?.unavailable && !filters.metric"
            class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
            data-test="metrics-unavailable"
        >
            Metric storage is busy and could not answer in time. Nothing is lost
            — retry in a moment.
        </p>

        <p
            v-else-if="catalogEmpty && !filters.metric"
            class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
            data-test="metrics-empty-window"
        >
            No metrics were reported in this window. Widen it, or check the
            project filter.
        </p>

        <!--
          Nothing picked yet: rather than an empty chart, the busiest metrics
          of each source as one-click starting points. The picker above holds the rest.
        -->
        <section
            v-else-if="!filters.metric"
            class="flex flex-col gap-3 rounded-lg border bg-card p-4"
            data-test="metrics-pick"
        >
            <p class="text-sm text-muted-foreground">
                Pick a metric to chart it. The busiest of each source:
            </p>
            <div v-if="!catalog" class="flex flex-col gap-2">
                <Skeleton class="h-8 w-full animate-pulse" />
                <Skeleton class="h-8 w-full animate-pulse" />
                <Skeleton class="h-8 w-2/3 animate-pulse" />
            </div>
            <ul v-else class="flex flex-col">
                <li
                    v-for="entry in suggestions"
                    :key="entry.name"
                    class="border-b last:border-0"
                >
                    <button
                        type="button"
                        class="flex w-full items-baseline justify-between gap-3 rounded-sm px-2 py-1.5 text-left transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        :data-test="`metrics-suggestion-${entry.name}`"
                        @click="selectMetric(entry.name)"
                    >
                        <span class="truncate font-mono text-sm">
                            {{ entry.name }}
                        </span>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        >
                            <template v-if="entry.services[0]">
                                <span class="font-mono">{{
                                    entry.services[0]
                                }}</span>
                                ·
                            </template>
                            {{ METRIC_TYPE_LABEL[entry.type] }}
                            <template v-if="entry.unit">
                                ·
                                <span class="font-mono">{{ entry.unit }}</span>
                            </template>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <template v-else>
            <MetricChart :series="series" :metric="filters.metric" />

            <p
                v-if="selectedEntry"
                class="text-xs text-muted-foreground"
                data-test="metrics-about"
            >
                <template v-if="selectedEntry.description">
                    {{ selectedEntry.description }} ·
                </template>
                {{ metricReading(selectedEntry) }}
                <template v-if="selectedEntry.unit">
                    · unit
                    <code class="font-mono">{{ selectedEntry.unit }}</code>
                </template>
            </p>
        </template>
    </div>
</template>
