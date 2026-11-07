<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';

/**
 * A small bar trend: one bar per bucket standing on a hairline baseline.
 *
 * Bars rather than a filled line because the things counted here are
 * discrete — an hour with three exceptions and an hour with none are two
 * different facts, and a line drawn between them invents the minutes in
 * between. Empty buckets show only the baseline, so a quiet week reads as
 * quiet rather than as missing.
 *
 * Pass `max` when several sparklines sit in one column: they then share a
 * scale and a tall bar means the same number in every row. Without it each
 * one scales to itself.
 *
 * Colour comes from the tokens: volume is chart data (`--chart-1`), an error
 * count is severity data (`--severity-error`). `errorValues`, when given, are
 * drawn over the volume bars as the part of each bucket that broke.
 */
const props = withDefaults(
    defineProps<{
        /** One number per bucket, oldest first. */
        values: number[];
        tone?: 'volume' | 'error';
        /** Errors per bucket, a subset of `values`, drawn on top. */
        errorValues?: number[];
        /** One label per bucket; supplying them turns tooltips on. */
        labels?: string[];
        /** The same buckets in UTC, printed beside the local label. */
        utcLabels?: string[];
        /** What the values count, for the tooltip. */
        unit?: string;
        /** A shared ceiling, so a column of sparklines compares honestly. */
        max?: number;
        /** Cap on a bar's width in px, so a week of daily buckets stays bars. */
        barMaxWidth?: number;
        height?: string | number;
    }>(),
    {
        tone: 'volume',
        errorValues: undefined,
        labels: undefined,
        utcLabels: undefined,
        unit: 'events',
        max: undefined,
        barMaxWidth: 12,
        height: 32,
    },
);

const { tokens } = useChartTokens();

const color = computed(() =>
    props.tone === 'error'
        ? tokens.value.severity.error
        : (tokens.value.palette[0] ?? tokens.value.foreground),
);

const hasValues = computed(() => props.values.length > 0);

const hasErrorValues = computed(
    () =>
        (props.errorValues?.length ?? 0) === props.values.length &&
        props.values.length > 0,
);

const hasLabels = computed(
    () =>
        (props.labels?.length ?? 0) === props.values.length &&
        props.values.length > 0,
);

const ceiling = computed(() => {
    const own = Math.max(0, ...props.values);

    return Math.max(1, props.max ?? own);
});

function tooltipFormatter(params: unknown): string {
    const first = Array.isArray(params) ? params[0] : params;
    const index = (first as { dataIndex?: number } | undefined)?.dataIndex ?? 0;
    const value = props.values[index] ?? 0;
    const errors = props.errorValues?.[index] ?? 0;
    const parts = [props.labels?.[index] ?? ''];

    if (props.utcLabels?.[index]) {
        parts.push(props.utcLabels[index]);
    }

    parts.push(`${value.toLocaleString()} ${props.unit}`);

    if (hasErrorValues.value && errors > 0) {
        parts.push(`${errors.toLocaleString()} errors`);
    }

    return parts.filter(Boolean).join(' · ');
}

const option = computed<BilisChartOption>(() => ({
    animation: false,
    grid: { left: 0, right: 0, top: 1, bottom: 1, containLabel: false },
    tooltip: hasLabels.value
        ? {
              trigger: 'axis',
              confine: true,
              textStyle: { fontSize: 12 },
              axisPointer: { type: 'shadow' },
              formatter: tooltipFormatter,
          }
        : undefined,
    xAxis: {
        type: 'category',
        data: props.values.map((_, index) => props.labels?.[index] ?? index),
        axisLabel: { show: false },
        axisTick: { show: false },
        // The baseline: what an empty bucket is drawn as.
        axisLine: {
            show: true,
            lineStyle: { color: tokens.value.border, width: 1 },
        },
    },
    yAxis: {
        type: 'value',
        show: false,
        min: 0,
        max: ceiling.value,
    },
    series: [
        {
            type: 'bar',
            silent: !hasLabels.value,
            barCategoryGap: '30%',
            barMaxWidth: props.barMaxWidth,
            itemStyle: { color: color.value, borderRadius: [1, 1, 0, 0] },
            data: props.values,
        },
        ...(hasErrorValues.value
            ? [
                  {
                      type: 'bar' as const,
                      silent: !hasLabels.value,
                      barGap: '-100%',
                      barCategoryGap: '30%',
                      barMaxWidth: props.barMaxWidth,
                      itemStyle: {
                          color: tokens.value.severity.error,
                          borderRadius: [1, 1, 0, 0] as [
                              number,
                              number,
                              number,
                              number,
                          ],
                      },
                      data: props.errorValues ?? [],
                  },
              ]
            : []),
    ],
}));
</script>

<template>
    <ChartCanvas v-if="hasValues" :option="option" :height="height" />
</template>
