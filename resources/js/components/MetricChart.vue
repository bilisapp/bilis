<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { parseTimestamp } from '@/lib/logs';
import {
    bucketLabel,
    DEFAULT_METRIC_PERCENTILE,
    escapeHtml,
    formatMetricValue,
    isGroupedResult,
    isPercentileKind,
    METRIC_PERCENTILES,
    metricUnitLabel,
    seriesName,
    seriesStats,
    visibleSeries,
    withoutOpenBucket,
} from '@/lib/metrics';
import type { MetricPercentile } from '@/lib/metrics';
import { cn } from '@/lib/utils';
import type { MetricSeriesResult } from '@/types';

/**
 * One metric over the window, one line per series.
 *
 * Every line is a data series, so every line spends the chart palette — cycled
 * through the five slots in the order the server ranked them — and nothing
 * else on the chart is coloured. Gaps are real: a bucket no point fell in is
 * drawn as a break rather than bridged, because a line interpolated across a
 * dead exporter reads as a healthy one.
 */
const props = withDefaults(
    defineProps<{
        /** Deferred: absent until the chart's own request lands. */
        series?: MetricSeriesResult;
        /** The metric the page asked for; null draws the "pick one" prompt. */
        metric: string | null;
        height?: string;
        /**
         * A reader's name for the chart ("Disk space used"). The exporter's
         * metric name is still printed beside it: the title is an
         * interpretation and the name is what the explorer is searched by.
         */
        title?: string;
        /** Where this chart opens with every control — the explorer. */
        explorerHref?: string;
    }>(),
    {
        series: undefined,
        height: '20rem',
        title: undefined,
        explorerHref: undefined,
    },
);

const { tokens } = useChartTokens();

/**
 * The percentile a grouped distribution is read at. Local rather than in the
 * URL: it narrows what is drawn, never what is queried — the response already
 * holds every stat — so switching it is instant and costs no round trip.
 */
const percentile = ref<MetricPercentile>(DEFAULT_METRIC_PERCENTILE);

/** What is drawn: the series, less a rate's still-open newest bucket. */
const result = computed(() =>
    props.series ? withoutOpenBucket(props.series) : undefined,
);

const kind = computed(() => result.value?.kind ?? null);

const unit = computed(() => result.value?.unit ?? '');

const perSecond = computed(() => kind.value === 'rate');

const grouped = computed(() =>
    result.value ? isGroupedResult(result.value) : false,
);

/** Only a grouped distribution needs the toggle; ungrouped draws every stat. */
const percentileGrouped = computed(
    () => isPercentileKind(kind.value) && grouped.value,
);

/** The toggle offers what the result actually carries, p50/p95/p99 first. */
const percentileOptions = computed(() => {
    const present = seriesStats(result.value?.series ?? []);

    return METRIC_PERCENTILES.filter((stat) => present.includes(stat));
});

const lines = computed(() =>
    result.value ? visibleSeries(result.value, percentile.value) : [],
);

const hasPoints = computed(() =>
    lines.value.some((line) => line.points.some((point) => point !== null)),
);

const intervalSeconds = computed(() => result.value?.intervalSeconds ?? 60);

/** Bucket starts are naive UTC; parseTimestamp appends the Z. */
const bucketDates = computed(() =>
    (result.value?.buckets ?? []).map((bucket) => parseTimestamp(bucket)),
);

const categories = computed(() =>
    bucketDates.value.map((date) => bucketLabel(date, intervalSeconds.value)),
);

const format = (value: number | null) =>
    formatMetricValue(value, unit.value, perSecond.value);

const axisName = computed(() => metricUnitLabel(unit.value, kind.value));

type TooltipItem = {
    marker?: string;
    seriesName?: string;
    value?: unknown;
    dataIndex?: number;
};

/**
 * Local time first, UTC beside it — the same pair the histograms show — and
 * every value in the metric's own unit. Series names are exporter data, so
 * they are escaped: the tooltip is HTML.
 */
const tooltipFormatter = (params: unknown): string => {
    const items = (Array.isArray(params) ? params : [params]) as TooltipItem[];
    const index = items[0]?.dataIndex ?? 0;
    const date = bucketDates.value[index];

    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }

    const header = `${bucketLabel(date, intervalSeconds.value)} · ${bucketLabel(date, intervalSeconds.value, true)} UTC`;

    const rows = items.map((item) => {
        const value = typeof item.value === 'number' ? item.value : null;

        return `${item.marker ?? ''}${escapeHtml(item.seriesName ?? '')} <strong>${escapeHtml(format(value))}</strong>`;
    });

    return [escapeHtml(header), ...rows].join('<br/>');
};

const option = computed<BilisChartOption>(() => {
    const palette = tokens.value.palette;

    return {
        animationDuration: 320,
        animationEasing: 'cubicOut',
        grid: { top: 52, right: 12, bottom: 4, left: 4, containLabel: true },
        legend: {
            type: 'scroll',
            top: 0,
            left: 0,
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 3,
            itemGap: 14,
            textStyle: { color: tokens.value.mutedForeground },
        },
        tooltip: {
            trigger: 'axis',
            confine: true,
            textStyle: { fontSize: 12 },
            formatter: tooltipFormatter,
        },
        xAxis: {
            type: 'category',
            data: categories.value,
            boundaryGap: false,
            axisTick: { show: false },
            axisLabel: {
                color: tokens.value.mutedForeground,
                fontSize: 10,
                interval: Math.max(
                    0,
                    Math.ceil(categories.value.length / 8) - 1,
                ),
            },
        },
        yAxis: {
            type: 'value',
            // A level (memory, queue depth) is read for its movement, and a
            // zero baseline flattens it; rates and percentiles stay anchored.
            scale: kind.value === 'value',
            name: axisName.value,
            nameLocation: 'end',
            nameTextStyle: {
                color: tokens.value.mutedForeground,
                fontSize: 10,
                align: 'left',
            },
            axisLabel: {
                color: tokens.value.mutedForeground,
                fontSize: 10,
                formatter: (value: number) => format(value),
            },
            splitLine: {
                lineStyle: { color: tokens.value.border, opacity: 0.6 },
            },
        },
        series: lines.value.map((line, index) => {
            const colour = palette.length
                ? palette[index % palette.length]
                : undefined;

            return {
                type: 'line' as const,
                name: seriesName(line, percentileGrouped.value),
                data: line.points,
                showSymbol: false,
                connectNulls: false,
                symbolSize: 5,
                lineStyle: { width: 1.5, color: colour },
                itemStyle: { color: colour },
                emphasis: { focus: 'series' as const },
            };
        }),
    };
});

const intervalLabel = computed(() => {
    const seconds = intervalSeconds.value;

    if (seconds >= 86_400) {
        return `${seconds / 86_400}d`;
    }

    if (seconds >= 3_600) {
        return `${seconds / 3_600}h`;
    }

    if (seconds >= 60) {
        return `${seconds / 60}m`;
    }

    return `${seconds}s`;
});
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
        data-test="metric-chart"
        :aria-label="
            metric
                ? `${title ?? metric} over the selected window`
                : 'Metric chart'
        "
    >
        <header class="flex flex-wrap items-center justify-between gap-2">
            <h2
                v-if="title"
                class="flex min-w-0 items-baseline gap-2 text-sm font-medium"
            >
                {{ title }}
                <span
                    class="truncate font-mono text-xs font-normal text-muted-foreground"
                >
                    {{ metric }}
                </span>
            </h2>
            <h2 v-else class="min-w-0 truncate font-mono text-sm font-medium">
                {{ metric ?? 'No metric selected' }}
            </h2>

            <div class="flex items-center gap-3">
                <p
                    v-if="series && hasPoints"
                    class="text-xs text-muted-foreground tabular-nums"
                >
                    {{ intervalLabel }} buckets
                </p>

                <Link
                    v-if="explorerHref"
                    :href="explorerHref"
                    class="rounded-sm text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    data-test="metric-chart-explore"
                >
                    Open in explorer
                </Link>

                <!--
                  Only a grouped distribution asks the reader to choose: three
                  percentiles for each of ten groups is thirty lines. Ungrouped,
                  every stat is drawn and the legend switches them.
                -->
                <div
                    v-if="percentileGrouped && percentileOptions.length > 1"
                    class="flex items-center rounded-md border p-0.5"
                    role="group"
                    aria-label="Percentile"
                    data-test="metric-chart-percentile"
                >
                    <button
                        v-for="option in percentileOptions"
                        :key="option"
                        type="button"
                        :class="
                            cn(
                                'rounded-sm px-1.5 py-0.5 font-mono text-xs transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                percentile === option
                                    ? 'bg-accent font-medium text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        :aria-pressed="percentile === option"
                        :data-test="`metric-chart-percentile-${option}`"
                        @click="percentile = option"
                    >
                        {{ option }}
                    </button>
                </div>
            </div>
        </header>

        <p
            v-if="metric === null"
            class="flex items-center text-sm text-muted-foreground"
            :style="{ minHeight: height }"
            data-test="metric-chart-pick"
        >
            Pick a metric above to chart it over this window.
        </p>

        <div
            v-else-if="!series"
            class="flex flex-col gap-2"
            data-test="metric-chart-skeleton"
        >
            <Skeleton
                class="w-full animate-pulse"
                :style="{ height: height }"
            />
            <Skeleton class="h-4 w-40 animate-pulse" />
        </div>

        <p
            v-else-if="series.unavailable"
            class="text-sm text-muted-foreground"
            data-test="metric-chart-unavailable"
        >
            Metric storage is busy and could not answer in time. Nothing is lost
            — retry in a moment.
        </p>

        <p
            v-else-if="series.kind === null || !hasPoints"
            class="text-sm text-muted-foreground"
            data-test="metric-chart-empty"
        >
            No data points for this metric in this window.
        </p>

        <template v-else>
            <ChartCanvas
                :option="option"
                :height="height"
                :aria-label="`${metric}, ${axisName}`"
            />

            <ul
                v-if="
                    series.truncatedGroups > 0 ||
                    series.droppedSeries > 0 ||
                    series.approximate
                "
                class="flex flex-col gap-0.5 text-xs text-muted-foreground"
                data-test="metric-chart-notes"
            >
                <li
                    v-if="series.truncatedGroups > 0"
                    data-test="metric-chart-truncated"
                >
                    {{ series.truncatedGroups.toLocaleString() }} more
                    {{ series.truncatedGroups === 1 ? 'group' : 'groups' }} not
                    shown — only the ten largest are drawn. Filter to narrow it
                    down.
                </li>
                <li
                    v-if="series.droppedSeries > 0"
                    data-test="metric-chart-dropped"
                >
                    {{ series.droppedSeries.toLocaleString() }}
                    {{
                        series.droppedSeries === 1
                            ? 'series was'
                            : 'series were'
                    }}
                    left out — past the read limit, or with bucket bounds that
                    could not be merged.
                </li>
                <li
                    v-if="series.approximate"
                    data-test="metric-chart-approximate"
                >
                    Approximate: a summary's quantiles are computed by each
                    client, so these lines average them across series rather
                    than recomputing them.
                </li>
            </ul>
        </template>
    </section>
</template>
