<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * The hairline-bounded strip of facts under a detail page's header: where a
 * thing lives and what it is, one `TraceFact` per cell.
 *
 * Two columns on a phone, three on a tablet, `columns` from `lg` up.
 */
const props = withDefaults(
    defineProps<{
        columns?: 4 | 5 | 6;
        class?: string;
    }>(),
    { columns: 5, class: undefined },
);

/** Whole class names, so Tailwind sees every one of them. */
const WIDE_COLUMNS = {
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
    6: 'lg:grid-cols-6',
} as const;
</script>

<template>
    <dl
        :class="
            cn(
                'grid grid-cols-2 gap-x-8 gap-y-4 border-y px-4 py-4 sm:grid-cols-3 md:px-10',
                WIDE_COLUMNS[props.columns],
                props.class,
            )
        "
    >
        <slot />
    </dl>
</template>
