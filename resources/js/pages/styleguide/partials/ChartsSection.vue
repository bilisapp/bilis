<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import DitherSparkline from '@/components/DitherSparkline.vue';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { SEVERITY_LEVELS } from '@/lib/logs';
import {
    CHART_INGEST_HOURS,
    CHART_INGEST_SERIES,
    CHART_SWATCHES,
    CHART_VOLUME_BY_SEVERITY,
    CHART_VOLUME_DAYS,
    SPARKLINE_ERRORS_24H,
    SPARKLINE_HOUR_LABELS,
    SPARKLINE_UTC_HOUR_LABELS,
    SPARKLINE_SERVICE_24H,
    SPARKLINE_SERVICE_ERRORS_24H,
    SPARKLINE_VOLUME_24H,
} from '@/pages/styleguide/data';
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';
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
</script>

<template>
    <SectionShell
        id="charts"
        title="Charts"
        description="Charts are Apache ECharts, always wrapped in ChartCanvas. Chart series are one of only two places this product spends colour — the interface itself is achromatic, and a series is data. Both data palettes come from the same place: the three stripes in the Bilis mark's tail, plus its navy body. The five series are those colours in order, authored twice so they hold on both the near-white and the dark card. A severity chart ignores this palette entirely and reads the --severity-* tokens directly, so its bars always agree with the dots in the log viewer."
    >
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
            <ul class="flex flex-col gap-2 rounded-lg border bg-card p-4">
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
                        :class="service.quiet ? 'text-muted-foreground' : ''"
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
    </SectionShell>
</template>
