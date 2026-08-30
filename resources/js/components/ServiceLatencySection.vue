<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { formatDuration, formatErrorRate } from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { ServiceLatencyResult } from '@/types';

const props = defineProps<{
    /** Deferred: absent until the panel's own request lands. */
    latency?: ServiceLatencyResult;
}>();

const { tokens } = useChartTokens();

const rows = computed(() => props.latency?.rows ?? []);

/**
 * The chart reads its colours from the CSS tokens, never from literals.
 *
 * p95 and p99 are two series of the same measurement, so they take the first
 * two chart slots rather than borrowing a severity hue — severity means log
 * level here, and a latency bar is not a log level.
 */
const option = computed<BilisChartOption>(() => {
    const services = rows.value.map((row) => row.serviceName);

    return {
        grid: { top: 24, right: 16, bottom: 8, left: 8, containLabel: true },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => formatDuration(Number(value)),
        },
        legend: {
            data: ['p95', 'p99'],
            textStyle: { color: tokens.value.mutedForeground },
            icon: 'roundRect',
            itemWidth: 10,
            itemHeight: 10,
            top: 0,
        },
        xAxis: {
            type: 'value',
            axisLabel: {
                color: tokens.value.mutedForeground,
                formatter: (value: number) => formatDuration(value),
            },
            splitLine: { lineStyle: { color: tokens.value.border } },
        },
        yAxis: {
            type: 'category',
            data: services,
            axisLabel: { color: tokens.value.mutedForeground },
            axisLine: { lineStyle: { color: tokens.value.border } },
        },
        series: [
            {
                name: 'p95',
                type: 'bar',
                data: rows.value.map((row) => row.p95Ms),
                itemStyle: { color: tokens.value.palette[0], borderRadius: 2 },
            },
            {
                name: 'p99',
                type: 'bar',
                data: rows.value.map((row) => row.p99Ms),
                itemStyle: { color: tokens.value.palette[1], borderRadius: 2 },
            },
        ],
    };
});

/*
 * Roughly one row of bars per service, floored so a single service does not get
 * a chart taller than its own data and capped so twenty services cannot push
 * everything below them off the screen.
 */
const chartHeight = computed(
    () => `${Math.min(20, Math.max(8, rows.value.length * 1.9))}rem`,
);
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
        data-test="service-latency"
    >
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold">Service latency</h2>
            <p class="text-xs text-muted-foreground">
                Over the selected window
            </p>
        </div>

        <div v-if="!latency" class="flex flex-col gap-2">
            <Skeleton class="h-36 w-full" />
            <Skeleton class="h-4 w-40" />
        </div>

        <p
            v-else-if="latency.unavailable"
            class="text-xs text-muted-foreground"
        >
            Latency is unavailable right now — trace storage is busy. The traces
            below are unaffected.
        </p>

        <p v-else-if="rows.length === 0" class="text-xs text-muted-foreground">
            No spans in this window.
        </p>

        <template v-else>
            <ChartCanvas :option="option" :height="chartHeight" />

            <div class="overflow-x-auto">
                <table class="w-full min-w-md text-xs">
                    <thead>
                        <tr class="border-b text-left text-muted-foreground">
                            <th class="py-1.5 pr-3 font-medium">Service</th>
                            <th class="py-1.5 pr-3 text-right font-medium">
                                Spans
                            </th>
                            <th class="py-1.5 pr-3 text-right font-medium">
                                p95
                            </th>
                            <th class="py-1.5 pr-3 text-right font-medium">
                                p99
                            </th>
                            <th class="py-1.5 text-right font-medium">
                                Errors
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.serviceName"
                            class="border-b last:border-0"
                        >
                            <td class="py-1.5 pr-3 break-all">
                                {{ row.serviceName || '—' }}
                            </td>
                            <td
                                class="py-1.5 pr-3 text-right font-mono tabular-nums"
                            >
                                {{ row.spans.toLocaleString() }}
                            </td>
                            <td
                                class="py-1.5 pr-3 text-right font-mono tabular-nums"
                            >
                                {{ formatDuration(row.p95Ms) }}
                            </td>
                            <td
                                class="py-1.5 pr-3 text-right font-mono tabular-nums"
                            >
                                {{ formatDuration(row.p99Ms) }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-1.5 text-right font-mono tabular-nums',
                                        row.errors > 0
                                            ? 'text-severity-error'
                                            : 'text-muted-foreground',
                                    )
                                "
                            >
                                {{ formatErrorRate(row.errorRate) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>
</template>
