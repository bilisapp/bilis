<script setup lang="ts">
import { computed, inject, onMounted } from 'vue';
import {
    demoRegistryKey,
    entryIdKey,
    slugify,
} from '@/pages/styleguide/context';

const props = defineProps<{
    title: string;
    description?: string;
}>();

const entryId = inject(entryIdKey, '');
const registry = inject(demoRegistryKey, null);

const anchor = computed(() =>
    entryId ? `${entryId}-${slugify(props.title)}` : slugify(props.title),
);

/*
 * The nav and the filter are built from what actually rendered: a demo is
 * never listed in a second place that can go stale.
 */
onMounted(() =>
    registry?.register(entryId, { id: anchor.value, title: props.title }),
);
</script>

<template>
    <div
        :id="anchor"
        class="scroll-mt-24 space-y-3 rounded-lg border bg-card p-4"
    >
        <div class="space-y-0.5">
            <h3 class="font-mono text-sm font-medium">{{ title }}</h3>
            <p v-if="description" class="text-xs text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div class="space-y-3">
            <slot />
        </div>
    </div>
</template>
