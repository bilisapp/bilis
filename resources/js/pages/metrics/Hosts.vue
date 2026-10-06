<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import HostsTable from '@/components/HostsTable.vue';
import MetricChart from '@/components/MetricChart.vue';
import MetricsTabs from '@/components/MetricsTabs.vue';
import ServerInstallCommand from '@/components/ServerInstallCommand.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
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

const ALL_PROJECTS = '__all__';

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const range = computed<LogRangePreset>(() =>
    presetForRange(props.filters.from, props.filters.to),
);

/** The host the charts are drawn for, once the server has said which. */
const selectedHost = computed(
    () => props.charts?.host ?? props.filters.host ?? null,
);

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

const projectValue = computed({
    get: () => props.filters.project ?? ALL_PROJECTS,
    set: (value: string) =>
        apply({ project: value === ALL_PROJECTS ? null : value, host: null }),
});

const rangeValue = computed({
    get: () => range.value,
    set: (preset: LogRangePreset) => {
        const minutes = RANGE_PRESETS.find(
            (option) => option.value === preset,
        )?.minutes;

        if (minutes === undefined) {
            return;
        }

        const to = new Date();
        const from = new Date(to.getTime() - minutes * 60_000);

        apply({ from: from.toISOString(), to: to.toISOString() });
    },
});

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

    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold tracking-tight">Metrics</h1>
            <p class="text-sm text-muted-foreground">
                Every machine running the Bilis agent — or any Collector with
                the host-metrics receiver — and how it is doing.
            </p>
        </div>

        <MetricsTabs :team-slug="teamSlug" :query="sharedQuery" />

        <!--
          Never having sent host metrics is a setup step, not an empty window:
          it gets the one command that fixes it.
        -->
        <div
            v-if="!hasHosts"
            class="flex flex-col gap-3 rounded-lg border bg-card p-6"
            data-test="hosts-empty-never"
        >
            <p class="text-sm font-medium">No hosts yet</p>
            <p class="max-w-prose text-sm text-muted-foreground">
                Run the agent on a Linux server and it appears here within a
                minute — CPU, memory, disks, network and its Docker containers.
                Use one of a project's API keys; the key-created dialog fills it
                in for you.
            </p>
            <ServerInstallCommand />
        </div>

        <template v-else>
            <div
                class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-card px-3 py-2"
                data-test="hosts-toolbar"
            >
                <div class="flex items-center gap-2">
                    <Label
                        class="text-xs text-muted-foreground"
                        for="hosts-project"
                    >
                        Project
                    </Label>
                    <Select v-model="projectValue">
                        <SelectTrigger
                            id="hosts-project"
                            size="sm"
                            class="min-w-40"
                            data-test="hosts-project"
                        >
                            <SelectValue placeholder="All projects" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL_PROJECTS">
                                All projects
                            </SelectItem>
                            <SelectItem
                                v-for="option in projects"
                                :key="option.slug"
                                :value="option.slug"
                            >
                                {{ option.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex items-center gap-2">
                    <Label
                        class="text-xs text-muted-foreground"
                        for="hosts-range"
                    >
                        Window
                    </Label>
                    <Select v-model="rangeValue">
                        <SelectTrigger
                            id="hosts-range"
                            size="sm"
                            class="min-w-40"
                            data-test="hosts-range"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="preset in RANGE_PRESETS"
                                :key="preset.value"
                                :value="preset.value"
                            >
                                {{ preset.label }}
                            </SelectItem>
                            <SelectItem value="custom" disabled>
                                Custom range
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <p class="text-xs text-muted-foreground">
                    Figures are the window's last 15 minutes.
                </p>

                <Button
                    v-if="canReset"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="ml-auto"
                    data-test="hosts-reset"
                    @click="reset"
                >
                    <RotateCcw class="size-4" />
                    Reset
                </Button>
            </div>

            <HostsTable
                :hosts="hosts"
                :selected="selectedHost"
                @select="selectHost"
            />

            <div
                v-if="!charts && (hosts?.hosts.length ?? 1) > 0"
                class="grid gap-4 lg:grid-cols-2"
                data-test="hosts-charts-skeleton"
            >
                <Skeleton
                    v-for="index in 4"
                    :key="index"
                    class="h-72 w-full animate-pulse"
                />
            </div>

            <p
                v-else-if="charts?.unavailable"
                class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
                data-test="hosts-charts-unavailable"
            >
                Metric storage is busy and could not answer in time. Nothing is
                lost — retry in a moment.
            </p>

            <p
                v-else-if="charts && charts.host === null && filters.host"
                class="rounded-lg border bg-card p-6 text-sm text-muted-foreground"
                data-test="hosts-charts-missing"
            >
                <span class="font-mono">{{ filters.host }}</span> did not report
                in this window.
            </p>

            <section
                v-for="group in chartGroups"
                :key="group.id"
                class="flex flex-col gap-3"
                :aria-label="group.label ?? `${selectedHost ?? 'Host'} charts`"
                :data-test="`hosts-charts-${group.id}`"
            >
                <h2
                    v-if="group.label"
                    class="text-sm font-medium text-muted-foreground"
                >
                    {{ group.label }}
                </h2>
                <div class="grid gap-4 lg:grid-cols-2">
                    <MetricChart
                        v-for="chart in group.charts"
                        :key="chart.id"
                        :series="chart.series"
                        :metric="chart.metric"
                        :title="chart.title"
                        :explorer-href="explorerHref(chart)"
                        height="14rem"
                        :data-test="`hosts-chart-${chart.id}`"
                    />
                </div>
            </section>
        </template>
    </div>
</template>
