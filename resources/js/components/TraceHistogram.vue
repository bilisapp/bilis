<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { parseTimestamp } from '@/lib/logs';
import type { TraceHistogram } from '@/types';

/**
 * Trace volume over the window, with the failed traces drawn in front.
 *
 * The trace list's twin of `LogsHistogram`, and deliberately the same strip:
 * same height, same axis, same bucket ladder, same click-to-zoom. What differs
 * is the encoding. A log line has one of six severities, so that strip stacks
 * six series; a trace either failed or it did not, and a failed trace is still
 * a trace, so stacking "errors" on top of "traces" would count it twice. The
 * error series is therefore drawn *over* the total, at the same x, in
 * severity-error — the same token a failed row wears in the list below — and
 * the total stays achromatic, because "how many" is a quantity and not a state.
 */
const props = defineProps<{
    histogram?: TraceHistogram;
}>();

const emit = defineEmits<{
    /** A bar was clicked: narrow the list to that bucket. */
    (event: 'zoom', payload: { from: string; to: string }): void;
}>();

const { tokens } = useChartTokens();

const buckets = computed(() => props.histogram?.buckets ?? []);

const intervalSeconds = computed(() => props.histogram?.intervalSeconds ?? 60);

/**
 * Bucket starts as instants, labelled in the reader's timezone — the same
 * clock `formatTimestamp()` puts on every row, so a spike lines up with the
 * traces it came from; the tooltip carries the UTC value alongside.
 */
const bucketDates = computed(() =>
    buckets.value.map((entry) => parseTimestamp(entry.at)),
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

const errors = computed(() => props.histogram?.errors ?? 0);

const busiest = computed(() =>
    buckets.value.reduce((peak, entry) => Math.max(peak, entry.traces), 0),
);

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
    series: [
        {
            type: 'bar' as const,
            name: 'Traces',
            barCategoryGap: '18%',
            itemStyle: { color: tokens.value.mutedForeground },
            data: buckets.value.map((entry) => entry.traces),
        },
        {
            type: 'bar' as const,
            name: 'Failed',
            // Drawn over the total at the same x, not beside or above it: a
            // failed trace is one of the traces, so it is a share, not a sum.
            barGap: '-100%',
            barCategoryGap: '18%',
            z: 3,
            itemStyle: { color: tokens.value.severity.error },
            data: buckets.value.map((entry) => entry.errors),
        },
    ],
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
        data-test="trace-histogram"
        aria-label="Trace volume over the selected window"
    >
        <header
            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <p>
                <template v-if="histogram && total > 0">
                    <span class="font-medium text-foreground"
                        >Traces per {{ intervalLabel }}</span
                    >
                    ·
                    <span class="tabular-nums">{{
                        total.toLocaleString()
                    }}</span>
                    in this window
                    <template v-if="errors > 0">
                        ·
                        <span
                            class="font-medium text-severity-error tabular-nums"
                            data-test="trace-histogram-errors"
                        >
                            {{ errors.toLocaleString() }} failed
                        </span>
                    </template>
                    · click a bar to zoom
                </template>
                <template v-else-if="histogram">
                    <span class="font-medium text-foreground"
                        >Trace volume</span
                    >
                </template>
                <template v-else>Counting traces…</template>
            </p>

            <div v-if="histogram && total > 0" class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5">
                    <span
                        class="size-2 rounded-sm bg-muted-foreground"
                        aria-hidden="true"
                    />
                    Traces
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span
                        class="size-2 rounded-sm bg-severity-error"
                        aria-hidden="true"
                    />
                    Failed
                </span>
                <span class="font-mono tabular-nums"
                    >peak {{ busiest.toLocaleString() }}</span
                >
            </div>
        </header>

        <Skeleton
            v-if="!histogram"
            class="h-16 w-full animate-pulse"
            data-test="trace-histogram-skeleton"
        />

        <div
            v-else-if="histogram.unavailable"
            class="flex h-10 items-center text-xs text-muted-foreground"
            data-test="trace-histogram-unavailable"
        >
            Volume is unavailable while trace storage catches up.
        </div>

        <div
            v-else-if="total === 0"
            class="flex h-16 items-end"
            data-test="trace-histogram-empty"
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
