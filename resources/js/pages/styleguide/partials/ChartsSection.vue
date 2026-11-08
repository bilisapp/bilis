<script setup lang="ts">
import { computed } from 'vue';
import BarSparkline from '@/components/BarSparkline.vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import DitherSparkline from '@/components/DitherSparkline.vue';
import IssueHistogram from '@/components/IssueHistogram.vue';
import ThresholdChart from '@/components/ThresholdChart.vue';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { SEVERITY_LEVELS } from '@/lib/logs';
import {
    CHART_INGEST_HOURS,
    CHART_INGEST_SERIES,
    CHART_SWATCHES,
    CHART_VOLUME_BY_SEVERITY,
    CHART_VOLUME_DAYS,
    DEMO_ALERT_COMPARE_PREVIEW,
    DEMO_ALERT_PREVIEW,
    demoIssueHistograms,
    SPARKLINE_ERRORS_24H,
    SPARKLINE_HOUR_LABELS,
    SPARKLINE_UTC_HOUR_LABELS,
    SPARKLINE_SERVICE_24H,
    SPARKLINE_SERVICE_ERRORS_24H,
    SPARKLINE_VOLUME_24H,
} from '@/pages/styleguide/data';
import DemoBlock from './DemoBlock.vue';
import SwatchGrid from './SwatchGrid.vue';

const { tokens } = useChartTokens();

const compactNumber = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

const formatCount = (value: number | string): string =>
    compactNumber.format(Number(value));

/**
 * Log volume per severity bucket, stacked. The bar colours come from the
 * `--severity-*` tokens so a bar matches the dot next to the same level in
 * the log viewer.
 */
const volumeOption = computed<BilisChartOption>(() => ({
    grid: { top: 32, right: 8, bottom: 4, left: 4, containLabel: true },
    legend: {
        top: 0,
        left: 0,
        icon: 'circle',
        itemWidth: 8,
        itemHeight: 8,
        itemGap: 16,
    },
    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        valueFormatter: (value) => formatCount(value as number),
    },
    xAxis: {
        type: 'category',
        data: CHART_VOLUME_DAYS,
        axisTick: { show: false },
    },
    yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { formatter: formatCount },
    },
    series: SEVERITY_LEVELS.map((level) => ({
        name: level.toUpperCase(),
        type: 'bar',
        stack: 'volume',
        barMaxWidth: 40,
        itemStyle: { color: tokens.value.severity[level] },
        emphasis: { focus: 'series' },
        data: CHART_VOLUME_BY_SEVERITY[level],
    })),
}));

/**
 * Ingest rate per project. No colours are set anywhere: the series pick up
 * `--chart-1` … `--chart-5` from the registered theme, in order.
 */
const ingestOption = computed<BilisChartOption>(() => ({
    grid: { top: 32, right: 8, bottom: 4, left: 4, containLabel: true },
    legend: {
        top: 0,
        left: 0,
        icon: 'circle',
        itemWidth: 8,
        itemHeight: 8,
        itemGap: 16,
    },
    tooltip: {
        trigger: 'axis',
        valueFormatter: (value) => `${formatCount(value as number)} rec/s`,
    },
    xAxis: {
        type: 'category',
        boundaryGap: false,
        data: CHART_INGEST_HOURS,
        axisTick: { show: false },
    },
    yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { formatter: formatCount },
    },
    series: CHART_INGEST_SERIES.map((series) => ({
        name: series.name,
        type: 'line',
        smooth: true,
        showSymbol: false,
        lineStyle: { width: 2 },
        emphasis: { focus: 'series' },
        data: series.values,
    })),
}));
/**
 * Two liveness rows: a busy shipper having a bad afternoon, and one that
 * stopped talking — the flatline is what "quiet" looks like.
 */
const SPARKLINE_SERVICES = [
    {
        name: 'checkout-api',
        values: SPARKLINE_SERVICE_24H,
        errors: SPARKLINE_SERVICE_ERRORS_24H,
        lastSeen: '2m ago',
        quiet: false,
    },
    {
        name: 'invoice-worker',
        values: Array.from({ length: 24 }, () => 0),
        errors: Array.from({ length: 24 }, () => 0),
        lastSeen: '9h ago',
        quiet: true,
    },
];

const issueHistograms = demoIssueHistograms();
</script>

<template>
    <div class="space-y-6">
        <SwatchGrid :swatches="CHART_SWATCHES" />

        <DemoBlock
            title="ChartCanvas — stacked bar"
            description="Log volume by severity, last 7 days. Bars are coloured from the --severity-* tokens via useChartTokens(), so they match the severity dots in the log viewer in both modes."
        >
            <ChartCanvas :option="volumeOption" height="18rem" />
        </DemoBlock>

        <DemoBlock
            title="ChartCanvas — line"
            description="Accepted ingest rate per project over 12 hours. No colours are set on the series at all — they take --chart-1 through --chart-5 from the theme, in order."
        >
            <ChartCanvas :option="ingestOption" height="18rem" />
        </DemoBlock>

        <DemoBlock
            title="BarSparkline"
            description="The redesign's trend: one bar per bucket on a hairline baseline, because hours with exceptions and hours without are discrete facts a filled line would smear together. Tone picks the token family exactly as DitherSparkline does. errorValues draws the broken share over the volume in --severity-error; max gives a column of sparklines one shared scale, so a tall bar means the same number on every row (the issue list and the dashboard's services use it); barMaxWidth keeps a week of daily buckets reading as bars."
        >
            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-2">
                    <p class="text-xs text-muted-foreground">
                        <span class="font-medium text-foreground"
                            >Logs per hour</span
                        >
                        · errors drawn over the volume
                    </p>
                    <BarSparkline
                        :values="SPARKLINE_VOLUME_24H"
                        :error-values="SPARKLINE_ERRORS_24H"
                        :labels="SPARKLINE_HOUR_LABELS"
                        :utc-labels="SPARKLINE_UTC_HOUR_LABELS"
                        :bar-max-width="28"
                        :height="96"
                        tone="volume"
                        unit="logs"
                    />
                </div>
                <div class="grid max-w-md gap-3">
                    <div
                        v-for="(row, index) in [
                            [0, 0, 0, 2, 1, 0, 0, 0, 0, 0, 3, 8],
                            [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 5],
                            [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2],
                        ]"
                        :key="index"
                        class="grid grid-cols-[minmax(0,1fr)_10.5rem] items-center gap-4 font-mono text-sm"
                    >
                        <span class="truncate font-semibold">{{
                            ['PaymentDeclinedError', 'TypeError', 'RangeError'][
                                index
                            ]
                        }}</span>
                        <BarSparkline :values="row" tone="error" :max="8" />
                    </div>
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="DitherSparkline"
            description="A trend small enough to sit inside a stat tile. The fill is a 4x4 Bayer ordered dither painted onto a repeating canvas tile — the 1-bit halftone, scaled by the device pixel ratio so the dots stay hard-edged. Tone picks the token family: volume is chart data (--chart-1), errors are severity data (--severity-error). Pass labels — one per value — to make it hoverable: the tooltip reads the label and the value, with the noun set by unit, and utcLabels adds the same bucket in UTC beside it, since labels themselves are drawn on the reader's clock. Without labels the chart stays silent."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2 rounded-lg border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Logs · 24h</p>
                    <p class="font-mono text-2xl">94,318</p>
                    <DitherSparkline
                        :values="SPARKLINE_VOLUME_24H"
                        :labels="SPARKLINE_HOUR_LABELS"
                        :utc-labels="SPARKLINE_UTC_HOUR_LABELS"
                        tone="volume"
                        unit="logs"
                    />
                    <p class="text-xs text-muted-foreground">
                        +38% vs prior day
                    </p>
                </div>

                <div class="space-y-2 rounded-lg border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Errors · 24h</p>
                    <p class="font-mono text-2xl text-severity-error">265</p>
                    <DitherSparkline
                        :values="SPARKLINE_ERRORS_24H"
                        :labels="SPARKLINE_HOUR_LABELS"
                        :utc-labels="SPARKLINE_UTC_HOUR_LABELS"
                        tone="error"
                        unit="errors"
                    />
                    <p class="text-xs text-muted-foreground">
                        +212% vs prior day
                    </p>
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="DitherSparkline — error overlay"
            description="A service row on the dashboard: pass errorValues alongside values and a second dithered line is drawn on top in --severity-error, so one glance answers both how much a shipper logged and how much of it broke. Errors are a subset of the totals, so the overlay shares the primary series' scale — no second axis, no stacking. The tooltip names both, and stays quiet about errors in the hours that had none."
        >
            <div class="overflow-x-auto">
                <ul
                    class="flex min-w-[24rem] flex-col gap-2 rounded-lg border bg-card p-4"
                >
                    <!--
                      The dashboard's fixed column template: identical on every
                      row so the charts share one x-origin and right edge, with
                      the status cell always rendered to keep the layout stable
                      whether or not a row wears the quiet pill.
                    -->
                    <li
                        v-for="service in SPARKLINE_SERVICES"
                        :key="service.name"
                        class="grid grid-cols-[7.5rem_minmax(4rem,1fr)_3.5rem_4.25rem] items-center gap-3 text-sm"
                    >
                        <span
                            class="min-w-0 truncate font-mono text-xs"
                            :class="
                                service.quiet ? 'text-muted-foreground' : ''
                            "
                            :title="service.name"
                        >
                            {{ service.name }}
                        </span>
                        <span class="min-w-0">
                            <DitherSparkline
                                :values="service.values"
                                :error-values="service.errors"
                                :labels="SPARKLINE_HOUR_LABELS"
                                :utc-labels="SPARKLINE_UTC_HOUR_LABELS"
                                :height="32"
                                tone="volume"
                                unit="logs"
                            />
                        </span>
                        <span
                            class="flex justify-center text-xs text-muted-foreground"
                        >
                            <span
                                v-if="service.quiet"
                                class="rounded-full border border-border px-1.5 py-0.5 text-xs tracking-wide uppercase"
                            >
                                quiet
                            </span>
                        </span>
                        <span
                            class="text-right text-xs text-muted-foreground tabular-nums"
                        >
                            {{ service.lastSeen }}
                        </span>
                    </li>
                </ul>
            </div>
        </DemoBlock>

        <DemoBlock
            title="IssueHistogram"
            description="How often one issue has happened, by the hour over a day or by the day over thirty. Every event of an issue is an exception, so the bars are error data and wear --severity-error, the same family the log viewer's error bars use. The tooltip carries users as well as events: a thousand events from one user and a thousand from a thousand are different incidents."
        >
            <div class="max-w-sm">
                <IssueHistogram :histogram="issueHistograms" />
            </div>
        </DemoBlock>

        <DemoBlock
            title="ThresholdChart"
            description="The alert rule editor's 24-hour preview. Each series the rule would produce is a line in the chart palette; the threshold is a dashed markLine and every stretch where the condition holds in any series is shaded, both in --severity-error, because crossing the line is the one thing on this chart that means something broke. The caption says how wide a point is and, for a count, that it was scaled to the rule's window. Then a compared rule (compare prop): each point is a multiple of the same window last week, so the threshold reads '3× normal'. Below: the loaded state, a source that refuses the query, and storage that did not answer."
        >
            <ThresholdChart
                :result="DEMO_ALERT_PREVIEW"
                source="logs"
                aggregation="count"
                condition=">"
                :threshold="25"
            />
            <!--
              A compared rule ("> 3× last week"): every point is a multiple
              of its baseline, so the axis and tooltip read "× normal".
            -->
            <ThresholdChart
                :result="DEMO_ALERT_COMPARE_PREVIEW"
                source="logs"
                aggregation="count"
                :compare="{ offset_seconds: 604800 }"
                condition=">"
                :threshold="3"
                height="10rem"
            />
            <div class="grid gap-4 md:grid-cols-3">
                <ThresholdChart
                    :result="null"
                    source="traces"
                    aggregation="p95"
                    condition=">"
                    :threshold="500"
                    height="6rem"
                />
                <ThresholdChart
                    :result="{
                        series: [],
                        unavailable: true,
                        reason: 'system.cpu.utilization is a gauge: a percentile needs a histogram.',
                        stepSeconds: 900,
                        windowSeconds: 300,
                        scaled: false,
                    }"
                    source="metrics"
                    aggregation="p95"
                    condition=">"
                    :threshold="0.9"
                    height="6rem"
                />
                <ThresholdChart
                    :result="{
                        series: [],
                        unavailable: true,
                        reason: null,
                        stepSeconds: 900,
                        windowSeconds: 300,
                        scaled: false,
                    }"
                    source="logs"
                    aggregation="count"
                    condition=">"
                    :threshold="25"
                    height="6rem"
                />
            </div>
        </DemoBlock>

        <div class="space-y-2 rounded-lg border bg-card p-4 text-sm">
            <h3 class="font-medium">Using charts</h3>
            <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                <li>
                    Import the wrapper from
                    <code class="font-mono text-xs"
                        >@/components/ChartCanvas.vue</code
                    >
                    and pass a typed
                    <code class="font-mono text-xs">option</code> (plus an
                    optional <code class="font-mono text-xs">height</code>). It
                    handles resize, dispose and the light/dark rebuild.
                </li>
                <li>
                    Register new chart types and components in
                    <code class="font-mono text-xs"
                        >resources/js/lib/echarts.ts</code
                    >
                    — one <code class="font-mono text-xs">use([...])</code> call
                    and one entry in
                    <code class="font-mono text-xs">BilisChartOption</code>.
                    Never import from
                    <code class="font-mono text-xs">echarts</code> directly, it
                    drags in the whole library.
                </li>
                <li>
                    Never hardcode a colour. The theme is built from the CSS
                    tokens; for per-series colours read them with
                    <code class="font-mono text-xs">useChartTokens()</code>.
                </li>
            </ul>
        </div>
    </div>
</template>
