<script setup lang="ts">
import { computed, ref } from 'vue';
import HostsTable from '@/components/HostsTable.vue';
import MetricChart from '@/components/MetricChart.vue';
import MetricsTabs from '@/components/MetricsTabs.vue';
import MetricsToolbar from '@/components/MetricsToolbar.vue';
import {
    DEMO_HOST_CPU,
    DEMO_HOSTS,
    DEMO_METRIC_ATTRIBUTES,
    DEMO_METRIC_CATALOG,
    DEMO_METRIC_DISTRIBUTION,
    DEMO_METRIC_DISTRIBUTION_GROUPED,
    DEMO_METRIC_EMPTY,
    DEMO_METRIC_RATE,
    DEMO_METRIC_SUMMARY,
    DEMO_METRIC_UNAVAILABLE,
    DEMO_METRIC_VALUE,
} from '@/pages/styleguide/data';
import type {
    LogRangePreset,
    MetricAggregation,
    MetricSeriesKind,
} from '@/types';
import DemoBlock from './DemoBlock.vue';

const demoProjects = [
    { name: 'Checkout', slug: 'checkout' },
    { name: 'Orders', slug: 'orders' },
];

/*
 * The toolbar demo is wired to local state, so every control can be driven
 * here exactly as it would drive the query string on the real page.
 */
const demoProject = ref<string | null>(null);
const demoMetric = ref<string | null>('process.memory.usage');
const demoService = ref<string | null>('checkout-api');
const demoWhere = ref<Record<string, string>>({ 'http.route': '/checkout' });
const demoGroupBy = ref<string | null>('http.request.method');
const demoAgg = ref<MetricAggregation>('avg');
const demoRange = ref<LogRangePreset>('1h');
const demoHost = ref<string | null>('vps-8d4cfe56');
const demoTabQuery = () => ({ project: 'checkout' });

/** The kind follows the picked metric, so the Combine control appears for a level. */
const demoKind = computed<MetricSeriesKind | null>(() => {
    const entry = DEMO_METRIC_CATALOG.metrics.find(
        (metric) => metric.name === demoMetric.value,
    );

    if (!entry) {
        return null;
    }

    if (entry.type === 'gauge' || (entry.type === 'sum' && !entry.monotonic)) {
        return 'value';
    }

    if (entry.type === 'sum') {
        return 'rate';
    }

    return entry.type === 'summary' ? 'summary' : 'distribution';
});

const demoCanReset = computed(
    () =>
        demoProject.value !== null ||
        demoMetric.value !== null ||
        demoService.value !== null ||
        demoGroupBy.value !== null ||
        Object.keys(demoWhere.value).length > 0 ||
        demoAgg.value !== 'avg' ||
        demoRange.value !== '1h',
);

function resetDemo() {
    demoProject.value = null;
    demoMetric.value = null;
    demoService.value = null;
    demoWhere.value = {};
    demoGroupBy.value = null;
    demoAgg.value = 'avg';
    demoRange.value = '1h';
}
</script>

<template>
    <div class="space-y-6">
        <DemoBlock
            title="MetricsTabs"
            description="the metrics page's two views — Hosts, every machine and a fixed set of charts for one, and the Explorer, one metric with every control — joined like the trace tabs. Only the window and the project travel between them; a host or a metric belongs to its own tab. The query is built when a tab is followed, so a relative window still ends now."
        >
            <MetricsTabs team-slug="acme" :query="demoTabQuery" />
        </DemoBlock>

        <DemoBlock
            title="HostsTable"
            description="one row per host.name the host-metrics receiver reported, with how it is doing now — the window's last fifteen minutes. CPU, memory and the fullest filesystem (its mount beside it) are meters on the magnitude ramp at fixed thresholds — half, three quarters, nine tenths — so 93 % disk reads the same on every visit; size, not meaning. A host that went quiet keeps its row with dashes and its last-seen in full weight: that is the finding. Click a row to chart it."
        >
            <HostsTable
                :hosts="DEMO_HOSTS"
                :selected="demoHost"
                @select="demoHost = $event"
            />
        </DemoBlock>

        <DemoBlock
            title="HostsTable — states"
            description="loading (the deferred list), storage too busy to answer, and a window in which no host reported."
        >
            <div class="flex flex-col gap-4">
                <HostsTable :selected="null" />
                <HostsTable
                    :hosts="{ hosts: [], unavailable: true }"
                    :selected="null"
                />
                <HostsTable
                    :hosts="{ hosts: [], unavailable: false }"
                    :selected="null"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="MetricChart — curated, with a title"
            description="how the Hosts tab draws a chart: a reader's name (CPU) with the exporter's metric name kept beside it, a ratio rescaled to percent, and a link that opens the same query in the explorer with every control."
        >
            <MetricChart
                :series="DEMO_HOST_CPU"
                metric="system.cpu.utilization"
                title="CPU"
                explorer-href="#metrics"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricsToolbar"
            description="the explorer's controls, and every one of them is a query-string parameter so a chart is a link. The metric picker groups the catalog by type — gauges, sums, then the distributions — with the unit beside each name; a metric the URL names that has gone quiet stays pickable under its own heading. Service is a datalist drawn from the services that reported the chosen metric. Group by and the Where filters offer the selected metric's own attribute keys, with each key's common values as suggestions; at most five filters, one value per key, each a removable chip. Combine (avg/min/max/sum) is only offered for a level — a rate is always summed and a distribution is read at a percentile, so for those it would change nothing. Switching metric drops the filters, because http.route means nothing on process.memory.usage."
        >
            <MetricsToolbar
                :projects="demoProjects"
                :project="demoProject"
                :catalog="DEMO_METRIC_CATALOG"
                :metric="demoMetric"
                :service="demoService"
                :attributes="DEMO_METRIC_ATTRIBUTES"
                :where="demoWhere"
                :group-by="demoGroupBy"
                :agg="demoAgg"
                :kind="demoKind"
                :range="demoRange"
                :can-reset="demoCanReset"
                @update:project="demoProject = $event"
                @update:metric="demoMetric = $event"
                @update:service="demoService = $event"
                @update:where="demoWhere = $event"
                @update:group-by="demoGroupBy = $event"
                @update:agg="demoAgg = $event"
                @update:range="demoRange = $event"
                @reset="resetDemo"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricsToolbar — loading"
            description="before the deferred catalog lands the picker is disabled and says what it is waiting for, and the pickers that depend on a metric stay disabled until there is one."
        >
            <MetricsToolbar
                :projects="demoProjects"
                :project="null"
                :metric="null"
                :service="null"
                :where="{}"
                :group-by="null"
                agg="avg"
                range="1h"
                :can-reset="false"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — level"
            description="a gauge or an up-down counter, combined by the toolbar's aggregation: here process.memory.usage in bytes, so the axis prints 1024-based sizes. Two scrapes never arrived and the line breaks there rather than bridging the gap — a line interpolated across a dead exporter reads as a healthy one. Every line spends the chart palette, cycled over five slots; nothing else on the chart is coloured."
        >
            <MetricChart
                :series="DEMO_METRIC_VALUE"
                metric="process.memory.usage"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — rate, grouped"
            description="a cumulative counter drawn as its increase per second, split by http.route. A {request} unit is a count, so the ticks are bare and the noun moves to the axis title (requests/s). Only the ten largest groups are drawn; the note says how many were left off rather than letting the legend imply it is complete."
        >
            <MetricChart
                :series="DEMO_METRIC_RATE"
                metric="http.server.requests"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — distribution"
            description="percentiles computed from histogram buckets, in the metric's unit — http.server.request.duration in seconds, printed through the same duration formatter the trace views use so 210 ms reads the same everywhere. Ungrouped, every stat is its own line and the legend switches them."
        >
            <MetricChart
                :series="DEMO_METRIC_DISTRIBUTION"
                metric="http.server.request.duration"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — distribution, grouped"
            description="the same histogram split by route. Three percentiles for each of ten groups would be thirty lines, so the reader picks one — p95 by default — and gets one line per group. The toggle narrows what is drawn, not what is fetched: every stat is already in the response, so switching is instant."
        >
            <MetricChart
                :series="DEMO_METRIC_DISTRIBUTION_GROUPED"
                metric="http.server.request.duration"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — summary"
            description="a summary's quantiles were computed by each client and cannot be merged exactly, so they are averaged across series and the chart says it is approximate. The dropped-series note covers series past the read limit, or with histogram bounds that could not be merged."
        >
            <MetricChart
                :series="DEMO_METRIC_SUMMARY"
                metric="rpc.server.duration"
                height="14rem"
            />
        </DemoBlock>

        <DemoBlock
            title="MetricChart — states"
            description="the designed non-chart states, in page order: no metric picked yet, the deferred series still loading (a pulsing skeleton at the chart's height), storage too busy to answer, and a metric that reported nothing in this window."
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <MetricChart :metric="null" height="8rem" />
                <MetricChart metric="http.server.requests" height="8rem" />
                <MetricChart
                    :series="DEMO_METRIC_UNAVAILABLE"
                    metric="http.server.requests"
                    height="8rem"
                />
                <MetricChart
                    :series="DEMO_METRIC_EMPTY"
                    metric="http.server.request.duration"
                    height="8rem"
                />
            </div>
        </DemoBlock>
    </div>
</template>
