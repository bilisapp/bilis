<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { SEVERITY_LEVELS } from '@/lib/logs';
import {
    CHART_INGEST_HOURS,
    CHART_INGEST_SERIES,
    CHART_SWATCHES,
    CHART_VOLUME_BY_SEVERITY,
    CHART_VOLUME_DAYS,
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
</script>

<template>
    <SectionShell
        id="charts"
        title="Charts"
        description="Charts are Apache ECharts, always wrapped in ChartCanvas. Five ordered series colours for volume histograms and error-rate lines: unlike the brand palette these are tokens, and they are tuned per mode: the navy and espresso entries lighten in dark mode so a five-series chart stays readable on the espresso background."
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
