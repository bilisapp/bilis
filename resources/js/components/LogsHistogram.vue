<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { parseTimestamp, SEVERITY_LEVELS } from '@/lib/logs';
import type { LogHistogram, SeverityLevel } from '@/types';

const props = defineProps<{
    histogram?: LogHistogram;
    /** Severities the viewer is filtered to; empty means all of them. */
    severity: SeverityLevel[];
}>();

const emit = defineEmits<{
    /** A bar was clicked: zoom the viewer into that bucket. */
    (event: 'zoom', payload: { from: string; to: string }): void;
}>();

const { tokens } = useChartTokens();

const buckets = computed(() => props.histogram?.buckets ?? []);

const intervalSeconds = computed(() => props.histogram?.intervalSeconds ?? 60);

/**
 * The levels that actually get a series. Filtering here rather than zeroing the
 * data keeps the stack order honest and the tooltip free of noise.
 */
const activeLevels = computed<SeverityLevel[]>(() =>
    props.severity.length > 0
        ? SEVERITY_LEVELS.filter((level) => props.severity.includes(level))
        : SEVERITY_LEVELS,
);

/**
 * Bucket starts as instants. They are labelled in the reader's timezone, the
 * same clock `formatTimestamp()` puts on every row, so a spike lines up with
 * the lines it came from; the tooltip carries the UTC value alongside.
 */
const bucketDates = computed(() =>
    buckets.value.map((entry) => parseTimestamp(entry.bucket)),
);

const pad = (value: number) => String(value).padStart(2, '0');

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/**
 * Label a bucket at the coarsest precision that still tells buckets apart.
 *
 * `utc` picks which clock reads the instant. The local branch uses the Date's
 * own getters, which resolve the zone — and its DST rules — for that instant
 * rather than applying a single offset to the whole window.
 */
const labelFor = (date: Date, utc = false): string => {
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const month = utc ? date.getUTCMonth() : date.getMonth();
    const day = utc ? date.getUTCDate() : date.getDate();
    const hours = utc ? date.getUTCHours() : date.getHours();
    const minutes = utc ? date.getUTCMinutes() : date.getMinutes();
    const seconds = utc ? date.getUTCSeconds() : date.getSeconds();

    if (intervalSeconds.value >= 86_400) {
        return `${MONTHS[month]} ${day}`;
    }

    if (intervalSeconds.value >= 60) {
        return `${pad(hours)}:${pad(minutes)}`;
    }

    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
};

/**
 * The hovered bucket, on both clocks, above the severity breakdown.
 *
 * The default axis tooltip would print the local axis label alone; a reader
 * comparing a spike against a UTC timestamp elsewhere needs the other value
 * without leaving the chart.
 */
const tooltipFormatter = (params: unknown): string => {
    const items = (Array.isArray(params) ? params : [params]) as {
        marker?: string;
        seriesName?: string;
        value?: unknown;
        dataIndex?: number;
    }[];

    const index = items[0]?.dataIndex ?? 0;
    const date = bucketDates.value[index];

    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }

    const header = `${labelFor(date)} · ${labelFor(date, true)} UTC`;

    const lines = items.map((item) => {
        const value = typeof item.value === 'number' ? item.value : 0;

        return `${item.marker ?? ''}${item.seriesName ?? ''} ${value.toLocaleString()}`;
    });

    return [header, ...lines].join('<br/>');
};

/*
 * Wrapped rather than passed by reference: `map` would hand the index in as
 * the `utc` flag and label every bucket but the first in the wrong zone.
 */
const categories = computed(() =>
    bucketDates.value.map((date) => labelFor(date)),
);

const total = computed(() => props.histogram?.total ?? 0);

const busiest = computed(() =>
    buckets.value.reduce((peak, entry) => Math.max(peak, entry.total), 0),
);

/**
 * A human-sized description of the bar width, for the strip's caption.
 */
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

const option = computed<BilisChartOption>(() => ({
    animationDuration: 320,
    animationEasing: 'cubicOut',
    grid: { top: 6, right: 0, bottom: 18, left: 0, containLabel: false },
    xAxis: {
        type: 'category',
        data: categories.value,
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { show: false },
        axisLabel: {
            color: tokens.value.mutedForeground,
            fontSize: 10,
            interval: Math.max(0, Math.ceil(categories.value.length / 8) - 1),
            margin: 8,
        },
        axisPointer: { type: 'shadow' },
    },
    yAxis: {
        type: 'value',
        show: false,
        splitLine: { show: false },
    },
    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        confine: true,
        textStyle: { fontSize: 12 },
        formatter: tooltipFormatter,
    },
    series: activeLevels.value.map((level) => ({
        type: 'bar' as const,
        name: level.toUpperCase(),
        stack: 'severity',
        barCategoryGap: '18%',
        itemStyle: { color: tokens.value.severity[level] },
        data: buckets.value.map((entry) => entry.counts[level] ?? 0),
    })),
}));

const onSelect = ({ dataIndex }: { dataIndex: number }) => {
    const start = bucketDates.value[dataIndex];

    if (!start || Number.isNaN(start.getTime())) {
        return;
    }

    emit('zoom', {
        from: start.toISOString(),
        to: new Date(
            start.getTime() + intervalSeconds.value * 1_000,
        ).toISOString(),
    });
};
</script>

<template>
    <section
        class="flex flex-col gap-2"
        data-test="logs-histogram"
        aria-label="Log volume over the selected window"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
        >
            <p class="text-xs text-muted-foreground">
                <template v-if="histogram && total > 0">
                    <span class="font-medium text-foreground"
                        >Lines per {{ intervalLabel }}</span
                    >
                    ·
                    <span class="tabular-nums">{{
                        total.toLocaleString()
                    }}</span>
                    in this window · peak
                    <span class="font-mono tabular-nums">{{
                        busiest.toLocaleString()
                    }}</span>
                    · click a bar to zoom
                </template>
                <template v-else-if="histogram">
                    <span class="font-medium text-foreground">Log volume</span>
                </template>
                <template v-else>Counting logs…</template>
            </p>

            <slot name="legend" />
        </header>

        <Skeleton
            v-if="!histogram"
            class="h-16 w-full animate-pulse"
            data-test="logs-histogram-skeleton"
        />

        <div
            v-else-if="histogram.unavailable"
            class="flex h-10 items-center text-xs text-muted-foreground"
            data-test="logs-histogram-unavailable"
        >
            Volume is unavailable while log storage catches up.
        </div>

        <div
            v-else-if="total === 0"
            class="flex h-16 items-end"
            data-test="logs-histogram-empty"
        >
            <div class="w-full border-b" />
        </div>

        <ChartCanvas
            v-else
            :option="option"
            height="4rem"
            class="cursor-pointer"
            @select="onSelect"
        />
    </section>
</template>
