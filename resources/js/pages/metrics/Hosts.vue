<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import FilterSelect from '@/components/FilterSelect.vue';
import HostsTable from '@/components/HostsTable.vue';
import MetricChart from '@/components/MetricChart.vue';
import MetricsLiveToggle from '@/components/MetricsLiveToggle.vue';
import MetricsTabs from '@/components/MetricsTabs.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionHeader from '@/components/SectionHeader.vue';
import ServerInstallCommand from '@/components/ServerInstallCommand.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useMetricsLive } from '@/composables/useMetricsLive';
import {
    DEFAULT_RANGE_PRESET,
    presetForRange,
    RANGE_PRESETS,
} from '@/lib/logs';
import {
    hostChartExplorerQuery,
    hostFilterQuery,
    metricWindow,
} from '@/lib/metrics';
import { hosts as metricsHosts, index as metricsIndex } from '@/routes/metrics';
import type {
    HostCharts,
    HostFilters,
    HostList,
    LogProject,
    LogRangePreset,
    Team,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: HostFilters;
    /** Has any project ever sent host metrics? A fact about the team, not the window. */
    hasHosts: boolean;
    /** Every host in the window with its "now" figures; deferred. */
    hosts?: HostList;
    /** The selected host's curated charts; deferred in a group of their own. */
    charts?: HostCharts;
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
            {
                title: 'Hosts',
                href: layoutProps.currentTeam
                    ? metricsHosts(layoutProps.currentTeam.slug)
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

/** The host the charts are drawn for, once the server has said which. */
const selectedHost = computed(
    () => props.charts?.host ?? props.filters.host ?? null,
);

/**
 * Live re-reads the list and the selected host's charts over the same
 * relative window, re-resolved against the clock.
 */
const { live, refreshing } = useMetricsLive({
    available: () => range.value !== 'custom',
    intervalSeconds: () =>
        props.charts?.charts[0]?.series.intervalSeconds ?? 60,
    refresh: (options) =>
        router.get(
            metricsHosts(teamSlug.value).url,
            hostFilterQuery(props.filters, range.value),
            { ...options, only: ['filters', 'hosts', 'charts'] },
        ),
});

const canReset = computed(
    () =>
        props.filters.project !== null ||
        props.filters.host !== null ||
        range.value !== DEFAULT_RANGE_PRESET,
);

/** What the Explorer tab inherits: the window and the project, no host. */
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

function apply(
    changes: {
        project?: string | null;
        host?: string | null;
        from?: string;
        to?: string;
    },
    only?: string[],
) {
    router.get(
        metricsHosts(teamSlug.value).url,
        { ...hostFilterQuery(props.filters, range.value), ...changes },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            ...(only ? { only } : {}),
        },
    );
}

/**
 * Picking a host only redraws its charts: the list above is the same list,
 * and reloading it would flash its skeleton under the reader's cursor.
 */
function selectHost(host: string) {
    if (host === selectedHost.value) {
        return;
    }

    apply({ host }, ['charts', 'filters']);
}

function pickProject(project: string | null) {
    apply({ project, host: null });
}

function pickRange(value: string | null) {
    const minutes = RANGE_PRESETS.find(
        (option) => option.value === value,
    )?.minutes;

    if (minutes === undefined || value === range.value) {
        return;
    }

    const to = new Date();
    const from = new Date(to.getTime() - minutes * 60_000);

    apply({ from: from.toISOString(), to: to.toISOString() });
}

const projectOptions = computed(() =>
    props.projects.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
);

/** A custom window is shown as such; it cannot be picked from here. */
const rangeOptions = computed(() => [
    ...RANGE_PRESETS.map((preset) => ({
        value: preset.value,
        label: preset.label,
    })),
    ...(range.value === 'custom'
        ? [{ value: 'custom', label: 'Custom range' }]
        : []),
]);

/** The header's scope: which project, over which window. */
const scope = computed(() => [
    props.projects.find((option) => option.slug === props.filters.project)
        ?.name ?? 'All projects',
    RANGE_PRESETS.find((preset) => preset.value === range.value)?.label ??
        'Custom range',
]);

const hostCount = computed(() =>
    props.hosts && !props.hosts.unavailable ? props.hosts.hosts.length : null,
);

function reset() {
    router.get(
        metricsHosts(teamSlug.value).url,
        {},
        { preserveScroll: true, replace: true },
    );
}

function explorerHref(chart: HostCharts['charts'][number]): string {
    return metricsIndex(teamSlug.value, {
        query: hostChartExplorerQuery(chart, props.filters, range.value),
    }).url;
}

/** Host charts first, then the containers it runs, as two labelled groups. */
const chartGroups = computed(() => {
    const charts = props.charts?.charts ?? [];

    return [
        {
            id: 'host',
            label: null,
            charts: charts.filter(
                (chart) => !chart.id.startsWith('container-'),
            ),
        },
        {
            id: 'containers',
            label: 'Containers',
            charts: charts.filter((chart) => chart.id.startsWith('container-')),
        },
    ].filter((group) => group.charts.length > 0);
});
</script>

<template>
    <Head title="Hosts" />

    <div class="flex flex-1 flex-col">
        <PageHeader title="Metrics" :eyebrow="scope">
            <p data-test="hosts-summary">
                <template v-if="!hasHosts">
                    Every machine running the Bilis agent, and how it is doing.
                </template>
                <template v-else-if="hostCount !== null">
                    <span class="font-medium text-foreground"
                        >{{ hostCount.toLocaleString() }}
                        {{ hostCount === 1 ? 'host' : 'hosts' }}</span
                    >
                    reporting · figures are the window's last 15 minutes
                </template>
                <template v-else>
                    Every machine running the Bilis agent · figures are the
                    window's last 15 minutes
                </template>
            </p>

            <template #actions>
                <MetricsTabs :team-slug="teamSlug" :query="sharedQuery" />
            </template>
        </PageHeader>

        <!--
          Never having sent host metrics is a setup step, not an empty window:
          it gets the one command that fixes it.
        -->
        <section
            v-if="!hasHosts"
            class="flex flex-col gap-3 border-t px-4 py-8 md:px-10"
            data-test="hosts-empty-never"
        >
            <p class="text-sm font-medium text-foreground">No hosts yet</p>
            <p class="max-w-prose text-sm text-muted-foreground">
                Run the agent on a Linux server — or any Collector with the
                host-metrics receiver — and it appears here within a minute:
                CPU, memory, disks, network and its Docker containers. Use one
                of a project's API keys; the key-created dialog fills it in for
                you.
            </p>
            <ServerInstallCommand class="max-w-3xl" />
        </section>

        <template v-else>
            <div class="px-4 pb-5 md:px-10">
                <div
                    class="flex flex-wrap items-center gap-2"
                    data-test="hosts-toolbar"
                >
                    <FilterSelect
                        id="hosts-range"
                        label="Window"
                        data-test="hosts-range"
                        :model-value="range"
                        :options="rangeOptions"
                        @update:model-value="pickRange"
                    />

                    <FilterSelect
                        v-if="projects.length > 1 || filters.project !== null"
                        id="hosts-project"
                        label="Project"
                        all-label="All projects"
                        data-test="hosts-project"
                        :model-value="filters.project"
                        :options="projectOptions"
                        @update:model-value="pickProject"
                    />

                    <span
                        class="mx-1 hidden h-5 w-px bg-border sm:block"
                        aria-hidden="true"
                    />

                    <MetricsLiveToggle
                        :live="live"
                        :available="range !== 'custom'"
                        :refreshing="refreshing"
                        @update:live="live = $event"
                    />

                    <Button
                        v-if="canReset"
                        type="button"
                        variant="ghost"
                        class="text-muted-foreground"
                        data-test="hosts-reset"
                        @click="reset"
                    >
                        <RotateCcw class="size-3.5" />
                        Reset
                    </Button>
                </div>
            </div>

            <HostsTable
                :hosts="hosts"
                :selected="selectedHost"
                @select="selectHost"
            />

            <div
                v-if="!charts && (hosts?.hosts.length ?? 1) > 0"
                class="grid gap-x-10 gap-y-8 px-4 py-8 md:px-10 lg:grid-cols-2"
                data-test="hosts-charts-skeleton"
            >
                <Skeleton
                    v-for="index in 4"
                    :key="index"
                    class="h-64 w-full animate-pulse"
                />
            </div>

            <StorageBusy
                v-else-if="charts?.unavailable"
                store="Metric storage"
                data-test="hosts-charts-unavailable"
            />

            <p
                v-else-if="charts && charts.host === null && filters.host"
                class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                data-test="hosts-charts-missing"
            >
                <span class="font-mono text-foreground">{{
                    filters.host
                }}</span>
                did not report in this window.
            </p>

            <!--
              The selected host's curated charts: one section per group, a
              heading on a hairline, the charts flat on the surface.
            -->
            <section
                v-for="group in chartGroups"
                :key="group.id"
                class="flex flex-col gap-6 px-4 pt-8 pb-6 md:px-10"
                :aria-label="group.label ?? `${selectedHost ?? 'Host'} charts`"
                :data-test="`hosts-charts-${group.id}`"
            >
                <SectionHeader
                    :note="`${group.charts.length} ${group.charts.length === 1 ? 'chart' : 'charts'}`"
                >
                    <template #title>
                        <span :class="group.label ? undefined : 'font-mono'">{{
                            group.label ?? selectedHost ?? 'Host'
                        }}</span>
                    </template>
                </SectionHeader>
                <div class="grid gap-x-10 gap-y-10 lg:grid-cols-2">
                    <MetricChart
                        v-for="chart in group.charts"
                        :key="chart.id"
                        :series="chart.series"
                        :metric="chart.metric"
                        :title="chart.title"
                        :explorer-href="explorerHref(chart)"
                        heading-tag="h3"
                        height="14rem"
                        :data-test="`hosts-chart-${chart.id}`"
                    />
                </div>
            </section>
        </template>
    </div>
</template>
