<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * The heading of a section on the work surface: a base-size title over a
 * hairline, with a quiet note — or the section's own links — on the right.
 *
 * Type and a rule, never a box. `as="summary"` lets a `<details>` use it as
 * its toggle, so a folding section reads exactly like a fixed one.
 */
const props = withDefaults(
    defineProps<{
        title?: string;
        /** A short muted note at the right: a count, a unit, a scope. */
        note?: string;
        as?: 'header' | 'summary' | 'div';
        class?: string;
    }>(),
    { title: undefined, note: undefined, as: 'header', class: undefined },
);
</script>

<template>
    <component
        :is="props.as"
        :class="
            cn(
                'flex items-baseline justify-between gap-3 border-b pb-2.5',
                props.class,
            )
        "
    >
        <h2 class="min-w-0 truncate text-base font-semibold tracking-tight">
            <slot name="title">{{ title }}</slot>
        </h2>
        <div
            v-if="$slots.aside || note"
            class="flex shrink-0 items-center gap-3 text-xs text-muted-foreground"
        >
            <slot name="aside">{{ note }}</slot>
        </div>
    </component>
</template>
