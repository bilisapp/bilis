<script setup lang="ts">
import { init } from 'echarts/core';
import type { EChartsType } from 'echarts/core';
import { computed, onBeforeUnmount, onMounted, shallowRef, watch } from 'vue';
import { useChartTokens } from '@/composables/useChartTokens';
import { registerChartTheme } from '@/lib/echarts';
import type { BilisChartOption } from '@/lib/echarts';

const props = withDefaults(
    defineProps<{
        /**
         * The ECharts option, typed against the features registered in
         * resources/js/lib/echarts.ts.
         */
        option: BilisChartOption;
        /** CSS height of the canvas; a number is treated as pixels. */
        height?: string | number;
        /**
         * Replace the option wholesale on every change. Keep this on unless
         * the chart animates between shapes of the *same* option.
         */
        notMerge?: boolean;
    }>(),
    {
        height: '18rem',
        notMerge: true,
    },
);

const container = shallowRef<HTMLDivElement | null>(null);
const chart = shallowRef<EChartsType | null>(null);

const { tokens, appearance } = useChartTokens();

const resolvedHeight = computed(() =>
    typeof props.height === 'number' ? `${props.height}px` : props.height,
);

let resizeObserver: ResizeObserver | null = null;

function renderChart() {
    if (!container.value) {
        return;
    }

    const theme = registerChartTheme(appearance.value, tokens.value);

    chart.value?.dispose();
    chart.value = init(container.value, theme, { renderer: 'canvas' });
    chart.value.setOption(props.option, { notMerge: true });
}

onMounted(() => {
    renderChart();

    resizeObserver = new ResizeObserver(() => chart.value?.resize());
    resizeObserver.observe(container.value as HTMLDivElement);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;

    chart.value?.dispose();
    chart.value = null;
});

// A theme is baked into the instance at init time, so an appearance flip has
// to rebuild it — the tokens are per-mode and ECharts will not re-resolve them.
watch(appearance, () => renderChart());

watch(
    () => props.option,
    (option) => {
        chart.value?.setOption(option, { notMerge: props.notMerge });
    },
    { deep: true },
);
</script>

<template>
    <div
        ref="container"
        class="w-full"
        :style="{ height: resolvedHeight }"
        role="img"
    />
</template>
