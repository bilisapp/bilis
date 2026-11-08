<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import {
    breachRanges,
    compareLabel,
    formatAlertValue,
    formatLabels,
    formatSeconds,
    previewSeriesName,
} from '@/lib/alerts';
import type { BilisChartOption } from '@/lib/echarts';
import type { AlertCompare, AlertCondition, AlertPreviewResult } from '@/types';

/**
 * A draft rule over the last 24 hours, against its threshold.
 *
 * The rule editor's preview: one line per series the rule would produce, in
 * the chart palette (each is a data series), the threshold as a dashed
 * markLine in the severity-error token, and every stretch where the
 * condition holds in any series shaded faintly in the same token — so "would
 * this have fired, and how often" is answered before the rule is saved. It
 * is not a saved chart and offers nothing a chart page would.
 *
 * `result` is null until the first preview lands; `loading` dims a stale
 * chart rather than blanking it while the next one is fetched.
 */
const props = withDefaults(
    defineProps<{
        result: AlertPreviewResult | null;
        source: string;
        aggregation: string;
        /** A compared rule: every point is a multiple of its baseline. */
        compare?: AlertCompare | null;
        condition: AlertCondition;
        threshold: number | null;
        loading?: boolean;
        height?: string | number;
    }>(),
    { compare: null, loading: false, height: '14rem' },
);

const { tokens } = useChartTokens();

const series = computed(() => props.result?.series ?? []);

const hasPoints = computed(() =>
    series.value.some((line) => line.points.some((point) => point[1] !== null)),
);

const threshold = computed(() =>
    props.threshold !== null && Number.isFinite(props.threshold)
        ? props.threshold
        : null,
);

const ranges = computed(() =>
    threshold.value === null
        ? []
        : breachRanges(series.value, props.condition, threshold.value),
);

/** Bucket starts in milliseconds, from the longest line. */
const starts = computed(() => {
    const longest = series.value.reduce<
        AlertPreviewResult['series'][number] | null
    >(
        (best, line) =>
            best === null || line.points.length > best.points.length
                ? line
                : best,
        null,
    );

    return (longest?.points ?? []).map((point) => point[0] * 1000);
});

const step = computed(() => (props.result?.stepSeconds ?? 60) * 1000);

const format = (value: number | null) =>
    formatAlertValue(value, props.source, props.aggregation, props.compare);

/** How many separate times the condition held: a rough "would have fired". */
const breaches = computed(() => ranges.value.length);

const option = computed<BilisChartOption>(() => {
    const palette = tokens.value.palette;
    const error = tokens.value.severity.error;

    const lines = series.value.map((line, index) => ({
        type: 'line' as const,
        name: previewSeriesName(line, props.aggregation),
        showSymbol: false,
        connectNulls: false,
        lineStyle: { width: 1.5 },
        color: palette[index % Math.max(1, palette.length)],
        emphasis: { focus: 'series' as const },
        data: line.points.map((point) => [point[0] * 1000, point[1]]),
        ...(index === 0
            ? {
                  markLine:
                      threshold.value === null
                          ? undefined
                          : {
                                silent: true,
                                symbol: 'none',
                                lineStyle: {
                                    color: error,
                                    type: 'dashed' as const,
                                    width: 1.25,
                                },
                                label: {
                                    position: 'insideEndTop' as const,
                                    color: error,
                                    formatter: `${props.condition} ${format(threshold.value)}`,
                                },
                                data: [{ yAxis: threshold.value }],
                            },
                  markArea: {
                      silent: true,
                      itemStyle: { color: error, opacity: 0.08 },
                      data: ranges.value.map(([from, to]) => [
                          { xAxis: starts.value[from] },
                          {
                              xAxis:
                                  (starts.value[to] ?? starts.value[from]) +
                                  step.value,
                          },
                      ]),
                  },
              }
            : {}),
    }));

    return {
        animation: false,
        grid: { left: 8, right: 16, top: 24, bottom: 8, containLabel: true },
        legend:
            series.value.length > 1
                ? {
                      type: 'scroll',
                      top: 0,
                      left: 0,
                      icon: 'roundRect',
                      itemWidth: 10,
                      itemHeight: 3,
                  }
                : { show: false },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value: unknown) =>
                format(typeof value === 'number' ? value : null),
        },
        xAxis: {
            type: 'time',
            axisLabel: { hideOverlap: true },
            splitLine: { show: false },
        },
        yAxis: {
            type: 'value',
            scale: false,
            axisLabel: {
                formatter: (value: number) => format(value),
            },
            // Keep the threshold on the chart even when every value is far below it.
            max: (extent: { max: number }) =>
                threshold.value !== null && threshold.value > extent.max
                    ? threshold.value * 1.1
                    : undefined,
        },
        series: lines,
    } as BilisChartOption;
});

const caption = computed(() => {
    if (!props.result || props.result.unavailable) {
        return '';
    }

    const each = formatSeconds(props.result.stepSeconds);
    const window = formatSeconds(props.result.windowSeconds);

    if (props.compare) {
        return `Each point is ${each} as × normal: divided by the same ${each} ${compareLabel(props.compare)}.`;
    }

    if (props.result.scaled) {
        return `Each point is ${each}, scaled to the rule's ${window} window.`;
    }

    return props.result.stepSeconds === props.result.windowSeconds
        ? `Each point is one ${window} window.`
        : `Each point is ${each}; the rule reads ${window} windows.`;
});
</script>

<template>
    <div class="flex flex-col gap-2" data-test="threshold-chart">
        <div
            class="flex items-baseline justify-between gap-4 text-xs text-muted-foreground"
        >
            <p>
                <span class="font-medium text-foreground">Last 24 hours</span>
                <template v-if="caption"> · {{ caption }}</template>
            </p>
            <p
                v-if="
                    result &&
                    !result.unavailable &&
                    hasPoints &&
                    threshold !== null
                "
                data-test="threshold-chart-breaches"
            >
                <span
                    :class="
                        breaches > 0
                            ? 'font-medium text-severity-error'
                            : 'text-muted-foreground'
                    "
                    >{{
                        breaches === 0
                            ? 'Never crossed'
                            : breaches === 1
                              ? 'Crossed once'
                              : `Crossed ${breaches} times`
                    }}</span
                >
            </p>
        </div>

        <Skeleton
            v-if="!result"
            class="w-full"
            :style="{
                height: typeof height === 'number' ? `${height}px` : height,
            }"
        />

        <template v-else-if="result.unavailable">
            <SurfaceMessage
                v-if="result.reason"
                :inset="false"
                class="py-6"
                title="This query cannot be previewed"
                data-test="threshold-chart-refused"
            >
                {{ result.reason }}
            </SurfaceMessage>
            <StorageBusy
                v-else
                store="Storage"
                :inset="false"
                class="py-6"
                data-test="threshold-chart-busy"
            />
        </template>

        <SurfaceMessage
            v-else-if="!hasPoints"
            :inset="false"
            class="py-6"
            data-test="threshold-chart-empty"
        >
            Nothing matched in the last 24 hours. With these filters the rule
            would see no data — see “When there is no data” below.
        </SurfaceMessage>

        <div
            v-else
            :class="
                loading ? 'opacity-60 transition-opacity' : 'transition-opacity'
            "
            :title="
                series.length === 1 ? formatLabels(series[0].labels) : undefined
            "
        >
            <ChartCanvas :option="option" :height="height" />
        </div>
    </div>
</template>
