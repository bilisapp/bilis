<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatNumber } from '@/lib/alerts';
import { traceHref } from '@/lib/traces';
import { cn } from '@/lib/utils';
import type { AlertExample } from '@/types';

/**
 * What a rule's reading was made of: the few distinct log messages or span
 * names behind it, most first, each with its count, its service and — when
 * the rows carried one — a request to open.
 *
 * The answer to "21 errors, but which?": the alert history lists what a
 * firing event caught, and a rule's page lists what its window holds now.
 * Counts sit in a fixed right lane so a column of them reads as a ranking;
 * a bar under each shows its share of the largest, in the neutral ink
 * (a count is magnitude, not severity).
 */
const props = withDefaults(
    defineProps<{
        examples: AlertExample[];
        teamSlug: string;
        /** Rows shown before the rest stays hidden. */
        limit?: number;
        /** Tighter rows, for a list nested in a history row. */
        dense?: boolean;
        class?: string;
    }>(),
    { limit: 10, dense: false, class: undefined },
);

const shown = computed(() => props.examples.slice(0, props.limit));

const largest = computed(() =>
    Math.max(1, ...shown.value.map((example) => example.count)),
);
</script>

<template>
    <ol
        :class="cn('flex flex-col', dense ? 'gap-1' : 'gap-2', props.class)"
        data-test="alert-example-list"
    >
        <li
            v-for="(example, index) in shown"
            :key="`${index}-${example.title}`"
            class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4"
        >
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex min-w-0 items-baseline gap-2">
                    <span
                        :class="
                            cn(
                                'truncate font-mono text-foreground',
                                dense ? 'text-xs' : 'text-sm',
                            )
                        "
                        :title="example.title"
                        >{{ example.title }}</span
                    >
                    <span
                        v-if="example.service"
                        class="shrink-0 text-xs text-muted-foreground"
                        >{{ example.service }}</span
                    >
                </div>
                <div
                    v-if="!dense"
                    class="h-0.5 rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <div
                        class="h-full rounded-full bg-foreground/40"
                        :style="{
                            width: `${Math.max(2, (example.count / largest) * 100)}%`,
                        }"
                    />
                </div>
                <div
                    v-if="example.detail || example.trace_id"
                    class="flex items-center gap-3 text-xs text-muted-foreground"
                >
                    <span v-if="example.detail">{{ example.detail }}</span>
                    <Link
                        v-if="example.trace_id"
                        :href="traceHref(teamSlug, example.trace_id)"
                        class="hover:text-foreground hover:underline hover:underline-offset-4"
                        data-test="alert-example-trace"
                        >Open a request</Link
                    >
                </div>
            </div>
            <span
                class="text-right font-mono text-xs text-foreground tabular-nums"
                >{{ formatNumber(example.count) }}×</span
            >
        </li>
    </ol>
</template>
