<script setup lang="ts">
import { CHART_SWATCHES } from '@/pages/styleguide/data';
import SectionShell from './SectionShell.vue';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SectionShell
        id="charts"
        title="Charts palette"
        description="Five ordered series colours for volume histograms and error-rate lines. Unlike the brand palette these are tokens, and they are tuned per mode: the navy and espresso entries lighten in dark mode so a five-series chart stays readable on the espresso background."
    >
        <SwatchGrid :swatches="CHART_SWATCHES" />

        <div class="rounded-lg border bg-card p-4">
            <p class="mb-3 text-xs text-muted-foreground">
                Stacked bar preview — log volume by severity, last 7 days
            </p>
            <div class="flex h-32 items-end gap-2">
                <div
                    v-for="(bar, index) in [72, 48, 90, 36, 64, 82, 54]"
                    :key="index"
                    class="flex flex-1 flex-col justify-end gap-px"
                >
                    <div
                        class="bg-chart-4"
                        :style="{ height: `${bar / 8}%` }"
                    />
                    <div
                        class="bg-chart-1"
                        :style="{ height: `${bar / 4}%` }"
                    />
                    <div
                        class="bg-chart-2"
                        :style="{ height: `${bar / 3}%` }"
                    />
                    <div class="bg-chart-3" :style="{ height: `${bar}%` }" />
                </div>
            </div>
        </div>
    </SectionShell>
</template>
