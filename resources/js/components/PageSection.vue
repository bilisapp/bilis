<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import SectionHeader from '@/components/SectionHeader.vue';
import { cn } from '@/lib/utils';

/**
 * One section of a page, on the gutter: a `SectionHeader`, an optional
 * sentence on what the section shows, and the body.
 *
 * Sections stack with `pt-10` between them; pass `class="pt-8"` for the first
 * one under a band. `flush` drops the gutter from the body — for a table whose
 * rows run edge to edge — while the heading keeps it. `collapsible` folds the
 * body behind the heading, closed until asked for.
 */
const props = withDefaults(
    defineProps<{
        title?: string;
        note?: string;
        /** One sentence under the heading saying what the section shows. */
        description?: string;
        flush?: boolean;
        collapsible?: boolean;
        class?: string;
    }>(),
    {
        title: undefined,
        note: undefined,
        description: undefined,
        flush: false,
        collapsible: false,
        class: undefined,
    },
);
</script>

<template>
    <section
        :class="
            cn(
                'flex min-w-0 flex-col pt-10',
                !flush && 'px-4 md:px-10',
                props.class,
            )
        "
    >
        <details v-if="collapsible" class="group">
            <SectionHeader
                as="summary"
                :title="title"
                class="cursor-pointer list-none [&::-webkit-details-marker]:hidden"
            >
                <template v-if="$slots.title" #title>
                    <slot name="title" />
                </template>
                <template #aside>
                    <slot name="aside">{{ note }}</slot>
                    <ChevronRight
                        class="size-3.5 transition-transform group-open:rotate-90"
                    />
                </template>
            </SectionHeader>
            <slot />
        </details>

        <template v-else>
            <SectionHeader
                :title="title"
                :note="note"
                :class="flush ? 'px-4 md:px-10' : undefined"
            >
                <template v-if="$slots.title" #title>
                    <slot name="title" />
                </template>
                <template v-if="$slots.aside" #aside>
                    <slot name="aside" />
                </template>
            </SectionHeader>
            <p
                v-if="description || $slots.description"
                :class="
                    cn(
                        'pt-3 text-xs text-muted-foreground',
                        flush && 'px-4 md:px-10',
                    )
                "
            >
                <slot name="description">{{ description }}</slot>
            </p>
            <div :class="cn('min-w-0', !flush && 'pt-4')">
                <slot />
            </div>
        </template>
    </section>
</template>
