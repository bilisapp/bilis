<script setup lang="ts">
import { provide } from 'vue';
import { entryIdKey } from '@/pages/styleguide/context';

const props = defineProps<{
    id: string;
    title: string;
    description: string;
    bodyClass?: string;
}>();

/*
 * Every DemoBlock below namespaces its anchor with the entry it belongs to,
 * so two entries can both showcase a "Heading" without colliding.
 */
provide(entryIdKey, props.id);
</script>

<template>
    <section :id="id" class="scroll-mt-24 space-y-6">
        <header class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
            <p class="max-w-3xl text-sm text-muted-foreground">
                {{ description }}
            </p>
        </header>

        <div :class="bodyClass ?? 'space-y-6'">
            <slot />
        </div>
    </section>
</template>
