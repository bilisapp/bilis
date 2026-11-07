<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * A sentence standing in for content that is not there: an empty window,
 * expired events, storage that did not answer. Text on the surface, never a
 * card — an optional title in ink, then the explanation in muted prose.
 *
 * `inset` (the default) puts it on the page gutter with room above and
 * below; turn it off inside a panel that already has its own padding.
 */
const props = withDefaults(
    defineProps<{
        title?: string;
        inset?: boolean;
        class?: string;
    }>(),
    { title: undefined, inset: true, class: undefined },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col gap-2',
                inset && 'px-4 py-8 md:px-10',
                props.class,
            )
        "
    >
        <p v-if="title" class="text-sm font-medium text-foreground">
            {{ title }}
        </p>
        <p class="max-w-prose text-sm text-muted-foreground">
            <slot />
        </p>
    </div>
</template>
