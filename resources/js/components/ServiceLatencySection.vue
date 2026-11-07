<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';
import { durationClass, formatDuration, formatErrorRate } from '@/lib/traces';
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
        grid: { top: 4, right: 16, bottom: 4, left: 0, containLabel: true },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (value) => formatDuration(Number(value)),
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
    <!--
      On the surface, not in a card: a chart band and then the table as a list,
      both on the page gutter so they share one left edge with the header.
    -->
    <section class="flex flex-1 flex-col" data-test="service-latency">
        <div v-if="!latency" class="flex flex-col gap-2 px-4 pb-5 md:px-10">
            <Skeleton class="h-36 w-full" />
            <Skeleton class="h-4 w-40" />
        </div>

        <p
            v-else-if="latency.unavailable"
            class="border-t px-4 py-8 text-sm text-muted-foreground md:px-10"
        >
            Latency is unavailable right now — trace storage is busy. The traces
            below are unaffected.
        </p>

        <p
            v-else-if="rows.length === 0"
            class="border-t px-4 py-8 text-sm text-muted-foreground md:px-10"
        >
            No spans in this window.
        </p>

        <template v-else>
            <div class="flex flex-col gap-2 px-4 pb-5 md:px-10">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-xs text-muted-foreground"
                >
                    <p>
                        <span class="font-medium text-foreground"
                            >p95 and p99 per service</span
                        >
                        · over the selected window
                    </p>
                    <!--
                      The legend is HTML on the caption line; its swatches are
                      the same chart tokens the series below are drawn in.
                    -->
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="size-2 rounded-sm bg-chart-1"
                                aria-hidden="true"
                            />
                            p95
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="size-2 rounded-sm bg-chart-2"
                                aria-hidden="true"
                            />
                            p99
                        </span>
                    </div>
                </div>
                <ChartCanvas :option="option" :height="chartHeight" />
            </div>

            <div class="overflow-x-auto border-t">
                <table class="w-full min-w-md text-sm">
                    <thead>
                        <tr class="border-b column-header text-left">
                            <th class="py-2.5 pr-6 pl-4 font-medium md:pl-10">
                                Service
                            </th>
                            <th class="w-28 py-2.5 pr-6 text-right font-medium">
                                Spans
                            </th>
                            <th class="w-24 py-2.5 pr-6 text-right font-medium">
                                p95
                            </th>
                            <th class="w-24 py-2.5 pr-6 text-right font-medium">
                                p99
                            </th>
                            <th
                                class="w-24 py-2.5 pr-4 text-right font-medium md:pr-10"
                            >
                                Errors
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.serviceName"
                            class="h-11 border-b border-border/60 transition-colors hover:bg-muted/50"
                        >
                            <td
                                class="py-2 pr-6 pl-4 font-mono break-all text-foreground md:pl-10"
                            >
                                {{ row.serviceName || '—' }}
                            </td>
                            <td
                                class="py-2 pr-6 text-right font-mono text-foreground/75 tabular-nums"
                            >
                                {{ row.spans.toLocaleString() }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-2 pr-6 text-right font-mono font-medium tabular-nums',
                                        durationClass(row.p95Ms),
                                    )
                                "
                            >
                                {{ formatDuration(row.p95Ms) }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-2 pr-6 text-right font-mono font-medium tabular-nums',
                                        durationClass(row.p99Ms),
                                    )
                                "
                            >
                                {{ formatDuration(row.p99Ms) }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-2 pr-4 text-right font-mono tabular-nums md:pr-10',
                                        row.errors > 0
                                            ? 'font-medium text-severity-error'
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
