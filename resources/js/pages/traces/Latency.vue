<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ServiceLatencySection from '@/components/ServiceLatencySection.vue';
import TracesTabs from '@/components/TracesTabs.vue';
import TracesToolbar from '@/components/TracesToolbar.vue';
import {
    DEFAULT_RANGE_PRESET,
    presetForRange,
    RANGE_PRESETS,
} from '@/lib/logs';
import { traceFilterQuery } from '@/lib/traces';
import {
    index as tracesIndex,
    latency as tracesLatency,
} from '@/routes/traces';
import type {
    LogProject,
    LogRangePreset,
    ServiceLatencyResult,
    Team,
    TraceFilters,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: TraceFilters;
    /** Has this team ever sent a span? A fact about the team, not the window. */
    hasTraces: boolean;
    /** Root services seen lately, for the toolbar's picker; deferred. */
    services?: string[];
    serviceLatency?: ServiceLatencyResult;
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
            {
                title: 'Service latency',
                href: layoutProps.currentTeam
                    ? tracesLatency(layoutProps.currentTeam.slug)
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

const windowLabel = computed(() =>
    range.value === 'custom'
        ? 'in the chosen window'
        : `in the ${(
              RANGE_PRESETS.find((preset) => preset.value === range.value)
                  ?.label ?? 'window'
          ).toLowerCase()}`,
);

/** The span total the summary sentence leads with. */
const spanTotal = computed(() =>
    (props.serviceLatency?.rows ?? []).reduce((sum, row) => sum + row.spans, 0),
);

const serviceCount = computed(() => props.serviceLatency?.rows.length ?? 0);

const canReset = computed(
    () =>
        props.filters.project !== null ||
        props.filters.service !== null ||
        props.filters.errors ||
        props.filters.minDuration !== null ||
        range.value !== DEFAULT_RANGE_PRESET,
);

/**
 * The filters as the tabs have to spell them — asked for at click time, not
 * cached, because a preset window is relative and a query string built at
 * render would carry the other tab back to a window that ended minutes ago.
 */
const query = () => traceFilterQuery(props.filters, range.value);

/**
 * The same contract the list has: the query string is the state, so a window
 * chosen here survives the walk back to the traces themselves.
 */
function apply(changes: Record<string, string | number | boolean | null>) {
    router.get(
        tracesLatency(teamSlug.value).url,
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
        tracesLatency(teamSlug.value).url,
        {},
        { preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head title="Service latency" />

    <div class="flex flex-1 flex-col overflow-y-auto">
        <PageHeader title="Service latency">
            <p data-test="latency-summary">
                <template
                    v-if="
                        serviceLatency &&
                        !serviceLatency.unavailable &&
                        serviceCount > 0
                    "
                >
                    <span class="font-medium text-foreground"
                        >{{ serviceCount.toLocaleString() }}
                        {{ serviceCount === 1 ? 'service' : 'services' }}</span
                    >
                    · {{ spanTotal.toLocaleString() }}
                    {{ spanTotal === 1 ? 'span' : 'spans' }}
                    {{ windowLabel }}
                </template>
                <template v-else>
                    p95 and p99 span duration per service
                </template>
            </p>

            <template #actions>
                <TracesTabs :team-slug="teamSlug" :query="query" />
            </template>
        </PageHeader>

        <!--
          The same toolbar as the list, minus the Live control: latency is a
          quantile over a window, and a chart that redrew itself every five
          seconds would be unreadable rather than current. Everything else is
          shared so a window chosen on either tab means the same thing on both.
        -->
        <div class="px-4 pb-5 md:px-10">
            <TracesToolbar
                :projects="projects"
                :project="filters.project"
                :services="services"
                :service="filters.service"
                :errors="filters.errors"
                :min-duration="filters.minDuration"
                :range="range"
                :can-reset="canReset"
                @update:project="apply({ project: $event })"
                @update:service="apply({ service: $event })"
                @update:errors="apply({ errors: $event })"
                @update:min-duration="apply({ min_duration: $event })"
                @update:range="applyRange"
                @reset="reset"
            />
        </div>

        <!--
          Never having sent a span is a setup problem, and it reads the same way
          here as it does on the list rather than as an empty chart.
        -->
        <div
            v-if="!hasTraces"
            class="flex flex-col gap-2 border-t px-4 py-8 md:px-10"
            data-test="latency-empty-never"
        >
            <p class="text-sm font-medium text-foreground">No traces yet</p>
            <p class="max-w-prose text-sm text-muted-foreground">
                Point an OpenTelemetry exporter at
                <code class="font-mono">/api/v1/traces</code> over OTLP/HTTP
                with a project API key. Latency appears here as soon as spans
                arrive.
            </p>
        </div>

        <ServiceLatencySection v-else :latency="serviceLatency" />
    </div>
</template>
