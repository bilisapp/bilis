<script setup lang="ts">
import { computed } from 'vue';
import ChartCanvas from '@/components/ChartCanvas.vue';
import { useChartTokens } from '@/composables/useChartTokens';
import type { BilisChartOption } from '@/lib/echarts';

/**
 * A small trend line whose fill is a 1-bit ordered dither rather than a solid
 * or a gradient — the old-Mac halftone, which is what a product with an
 * achromatic chrome can afford to spend on a fill.
 *
 * Colour comes from the tokens, never from a literal: a volume series is
 * chart data (`--chart-1`), an error count is severity data and takes
 * `--severity-error`, exactly as the log viewer's histogram does.
 */
const props = withDefaults(
    defineProps<{
        /** One number per bucket, oldest first. */
        values: number[];
        /** Which token family the series is drawn from. */
        tone?: 'volume' | 'error';
        /** CSS height of the sparkline; a number is treated as pixels. */
        height?: string | number;
    }>(),
    {
        tone: 'volume',
        height: 40,
    },
);

const { tokens } = useChartTokens();

const color = computed(() =>
    props.tone === 'error'
        ? tokens.value.severity.error
        : (tokens.value.palette[0] ?? tokens.value.foreground),
);

/**
 * The 4×4 Bayer threshold matrix. A cell is painted when its threshold falls
 * under the target density, which is what turns a flat area into an evenly
 * distributed stipple instead of a random noise field.
 */
const BAYER_4X4 = [
    [0, 8, 2, 10],
    [12, 4, 14, 6],
    [3, 11, 1, 9],
    [15, 7, 13, 5],
];

/** Roughly how many of the sixteen cells are lit. */
const DITHER_DENSITY = 7 / 16;

/** Each matrix cell is this many CSS pixels across, so the dots read as dots. */
const DOT_SIZE = 2;

/**
 * Paint the Bayer matrix onto a transparent tile that ECharts can repeat.
 *
 * Scaled by the device pixel ratio so the dots stay hard-edged on retina —
 * a fractional dot would be antialiased into a grey wash, which is precisely
 * the look this is avoiding.
 */
function ditherPattern(fill: string): HTMLCanvasElement | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const ratio =
        typeof window === 'undefined'
            ? 1
            : Math.max(1, Math.round(window.devicePixelRatio || 1));
    const cell = DOT_SIZE * ratio;

    const canvas = document.createElement('canvas');
    canvas.width = BAYER_4X4.length * cell;
    canvas.height = BAYER_4X4.length * cell;

    const context = canvas.getContext('2d');

    if (!context) {
        return null;
    }

    context.fillStyle = fill;

    const threshold = DITHER_DENSITY * BAYER_4X4.length * BAYER_4X4.length;

    BAYER_4X4.forEach((row, y) => {
        row.forEach((value, x) => {
            if (value >= threshold) {
                return;
            }

            context.fillRect(x * cell, y * cell, cell, cell);
        });
    });

    return canvas;
}

/**
 * The dither tile, rebuilt whenever the resolved colour changes — the tokens
 * are per-mode, so a light/dark flip needs a repainted pattern.
 *
 * ChartCanvas's cloneOption only deep-clones plain objects and arrays, so the
 * canvas element survives the clone by reference.
 */
const pattern = computed(() => ditherPattern(color.value));

const hasValues = computed(() => props.values.length > 0);

/** True while nothing has been logged: the baseline stays flat, not rescaled. */
const isFlat = computed(() => props.values.every((value) => value === 0));

const option = computed<BilisChartOption>(() => ({
    animation: false,
    grid: { left: 0, right: 0, top: 2, bottom: 0, containLabel: false },
    xAxis: {
        type: 'category',
        show: false,
        boundaryGap: false,
        data: props.values.map((_, index) => String(index)),
    },
    yAxis: {
        type: 'value',
        show: false,
        min: 0,
        // An all-zero window would otherwise be scaled up into a noise floor.
        max: isFlat.value ? 1 : undefined,
    },
    series: [
        {
            type: 'line',
            symbol: 'none',
            silent: true,
            lineStyle: { width: 1, color: color.value },
            areaStyle: pattern.value
                ? { color: { image: pattern.value, repeat: 'repeat' } }
                : { color: color.value, opacity: 0.15 },
            data: props.values,
        },
    ],
}));
</script>

<template>
    <ChartCanvas v-if="hasValues" :option="option" :height="height" />
</template>
