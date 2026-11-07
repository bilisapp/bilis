<script setup lang="ts">
/**
 * A section heading on the work surface.
 *
 * `small` is the in-page section: a base-size title over a hairline, with an
 * optional muted description and room on the right for the section's own
 * actions (`#actions`). It is type and a rule, never a box — the rows or form
 * below it sit straight on the surface. `default` is the larger standalone
 * title kept for the few places that still want one.
 */
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header
        v-if="variant === 'small'"
        class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3 border-b pb-2.5"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <h2 class="text-base font-semibold tracking-tight text-foreground">
                {{ title }}
            </h2>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </header>

    <header v-else class="mb-8 space-y-0.5">
        <h2 class="text-3xl font-semibold tracking-tight">
            {{ title }}
        </h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
