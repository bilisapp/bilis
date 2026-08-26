<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { Swatch } from '@/pages/styleguide/data';

defineProps<{
    swatches: Swatch[];
}>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <figure
            v-for="swatch in swatches"
            :key="swatch.name"
            class="overflow-hidden rounded-lg border bg-card"
        >
            <div
                :class="
                    cn(
                        'flex h-20 items-center justify-center text-lg font-medium',
                        swatch.className,
                    )
                "
            >
                {{ swatch.preview }}
            </div>
            <figcaption class="space-y-1 border-t p-3">
                <p class="text-sm font-medium">{{ swatch.name }}</p>
                <p class="font-mono text-xs text-muted-foreground">
                    {{ swatch.label }}
                    <template v-if="swatch.hex"> · {{ swatch.hex }}</template>
                </p>
                <p v-if="swatch.note" class="text-xs text-muted-foreground">
                    {{ swatch.note }}
                </p>
            </figcaption>
        </figure>
    </div>
</template>
