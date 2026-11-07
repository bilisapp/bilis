<script setup lang="ts">
import { computed, ref } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { formatCount } from '@/lib/issues';
import { cn } from '@/lib/utils';
import type { IssueHistograms } from '@/types';

/**
 * How often one issue has happened: the last day by the hour, or the last
 * thirty days by the day.
 *
 * Bars in the severity error token: every event of an issue is an exception,
 * so the chart is error data and wears the error family, as the log viewer's
 * error bars do. The tooltip carries users too — a thousand events from one
 * user and a thousand from a thousand are different incidents.
 */
const props = defineProps<{
    histogram?: IssueHistograms;
}>();

const range = ref<'day' | 'month'>('day');

const { tokens } = useChartTokens();

const series = computed(() => props.histogram?.[range.value]);

const buckets = computed(() => series.value?.buckets ?? []);

const daily = computed(() => (series.value?.intervalSeconds ?? 3600) >= 86_400);

const pad = (value: number) => String(value).padStart(2, '0');

const label = (seconds: number): string => {
    const date = new Date(seconds * 1000);

    return daily.value
        ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        : `${pad(date.getHours())}:00`;
};

const total = computed(() =>
    buckets.value.reduce((sum, bucket) => sum + bucket.events, 0),
);

const option = computed<BilisChartOption>(() => ({
    animationDuration: 320,
    animationEasing: 'cubicOut',
    grid: { top: 6, right: 0, bottom: 18, left: 0, containLabel: false },
    xAxis: {
        type: 'category',
        data: buckets.value.map((bucket) => label(bucket.bucket)),
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { show: false },
        axisLabel: {
            color: tokens.value.mutedForeground,
            fontSize: 10,
            interval: Math.max(0, Math.ceil(buckets.value.length / 8) - 1),
            margin: 8,
        },
        axisPointer: { type: 'shadow' },
    },
    yAxis: { type: 'value', show: false, splitLine: { show: false } },
    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        confine: true,
        textStyle: { fontSize: 12 },
        formatter: (params: unknown) => {
            const items = (Array.isArray(params) ? params : [params]) as {
                dataIndex?: number;
                marker?: string;
            }[];
            const bucket = buckets.value[items[0]?.dataIndex ?? 0];

            if (!bucket) {
                return '';
            }

            const at = new Date(bucket.bucket * 1000);
            const when = daily.value
                ? at.toLocaleDateString(undefined, { dateStyle: 'medium' })
                : at.toLocaleString(undefined, {
                      dateStyle: 'medium',
                      timeStyle: 'short',
                  });

            return [
                when,
                `${items[0]?.marker ?? ''}Events ${bucket.events.toLocaleString()}`,
                `Users ${bucket.users.toLocaleString()}`,
            ].join('<br/>');
        },
    },
    series: [
        {
            type: 'bar' as const,
            name: 'Events',
            barCategoryGap: '18%',
            itemStyle: { color: tokens.value.severity.error },
            data: buckets.value.map((bucket) => bucket.events),
        },
    ],
}));
</script>

<template>
    <section
        class="flex flex-col gap-2 rounded-lg border bg-card p-4"
        data-test="issue-histogram"
    >
        <header class="flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">
                Events
                <span
                    v-if="histogram"
                    class="ml-1 font-mono font-normal text-muted-foreground tabular-nums"
                >
                    {{ formatCount(total) }}
                </span>
            </h2>
            <div
                class="inline-flex rounded-md border p-0.5 text-xs"
                role="tablist"
                aria-label="Chart range"
            >
                <button
                    v-for="option in [
                        { value: 'day', label: '24h' },
                        { value: 'month', label: '30d' },
                    ] as const"
                    :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="range === option.value"
                    :data-test="`issue-histogram-${option.value}`"
                    :class="
                        cn(
                            'rounded-[5px] px-2 py-0.5 transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            range === option.value
                                ? 'bg-accent font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                    @click="range = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </header>

        <Skeleton v-if="!histogram" class="h-24 w-full" />
        <p
            v-else-if="series?.unavailable"
            class="py-8 text-center text-xs text-muted-foreground"
        >
            Event counts are busy — retry in a moment.
        </p>
        <ChartCanvas v-else :option="option" :height="96" />
    </section>
</template>
