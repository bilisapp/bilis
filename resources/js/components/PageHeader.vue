<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePageTrail } from '@/composables/usePageTrail';
import { cn } from '@/lib/utils';

/**
 * The top of every in-app page: where you are, what this is, what it says.
 *
 * Four parts, in reading order. The eyebrow is the way back — the layout's
 * breadcrumbs minus the page itself, or an explicit context line when the
 * page is a top-level section. The title is the one large thing on the page.
 * The summary (default slot) is a single sentence of numbers in which only the
 * figure that matters takes a colour. Actions sit on the right and drop below
 * on a narrow screen.
 *
 * It sits directly on the work surface, with no card around it: the header is
 * type, not a box.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        /** Replaces the breadcrumb trail as the eyebrow, e.g. a scope. */
        eyebrow?: string[];
        /** Extra classes for the outer element (padding overrides). */
        class?: string;
    }>(),
    { eyebrow: undefined, class: undefined },
);

const trail = usePageTrail();

/** The way back: every crumb but the page itself. */
const crumbs = computed(() =>
    props.eyebrow
        ? []
        : trail.value.slice(0, Math.max(0, trail.value.length - 1)),
);
</script>

<template>
    <header
        :class="
            cn(
                'flex flex-wrap items-end justify-between gap-x-8 gap-y-4 px-4 pt-6 pb-5 md:px-10 md:pt-10 md:pb-6',
                props.class,
            )
        "
        data-test="page-header"
    >
        <div class="flex min-w-0 flex-col gap-2">
            <nav
                v-if="crumbs.length > 0 || (eyebrow && eyebrow.length > 0)"
                aria-label="Breadcrumb"
                class="flex min-w-0 flex-wrap items-center gap-2 eyebrow"
            >
                <template v-if="eyebrow">
                    <template v-for="(part, index) in eyebrow" :key="index">
                        <span v-if="index > 0" class="text-input">/</span>
                        <span class="truncate">{{ part }}</span>
                    </template>
                </template>
                <template v-else>
                    <template v-for="(crumb, index) in crumbs" :key="index">
                        <span v-if="index > 0" class="text-input">/</span>
                        <Link
                            :href="crumb.href"
                            class="truncate transition-colors hover:text-foreground"
                        >
                            {{ crumb.title }}
                        </Link>
                    </template>
                </template>
            </nav>

            <h1
                class="truncate text-2xl leading-8 font-semibold tracking-tight text-foreground md:text-title"
            >
                <slot name="title">{{ title }}</slot>
            </h1>

            <div
                v-if="$slots.default"
                class="text-sm leading-5 text-muted-foreground"
            >
                <slot />
            </div>
        </div>

        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
