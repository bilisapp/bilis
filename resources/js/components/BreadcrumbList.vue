<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatTimestamp } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { Breadcrumb } from '@/types';

/**
 * What the application did just before it failed, most recent first.
 *
 * The first few are shown and the rest are one click away: the crumbs
 * nearest the exception are the ones that explain it. A crumb's level only
 * borrows colour when it is itself an error — the same rule the log stream
 * follows — so the list does not turn into a second severity chart.
 */
const props = withDefaults(
    defineProps<{
        breadcrumbs: Breadcrumb[];
        /** How many crumbs show before "Show all". */
        initial?: number;
    }>(),
    { initial: 8 },
);

const expanded = ref(false);

const visible = computed(() =>
    expanded.value
        ? props.breadcrumbs
        : props.breadcrumbs.slice(0, props.initial),
);

const isError = (crumb: Breadcrumb) =>
    ['error', 'fatal', 'critical'].includes(crumb.level.toLowerCase());
</script>

<template>
    <div data-test="breadcrumbs">
        <p
            v-if="breadcrumbs.length === 0"
            class="py-3 text-sm text-muted-foreground"
        >
            No breadcrumbs were sent with this event.
        </p>

        <ol v-else>
            <li
                v-for="(crumb, index) in visible"
                :key="index"
                class="grid min-h-11 grid-cols-[6rem_minmax(0,1fr)] items-center gap-x-4 border-b border-border/60 py-2 text-xs sm:grid-cols-[6rem_minmax(0,1fr)_11rem]"
            >
                <span
                    :class="
                        cn(
                            'truncate font-medium',
                            isError(crumb)
                                ? 'text-severity-error'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    {{ crumb.category || crumb.type || 'default' }}
                </span>
                <span class="min-w-0">
                    <span
                        class="block truncate font-mono text-foreground/80"
                        :title="crumb.message"
                        >{{ crumb.message || '—' }}</span
                    >
                    <span
                        v-if="crumb.data"
                        class="block truncate font-mono text-muted-foreground"
                        :title="crumb.data"
                    >
                        {{ crumb.data }}
                    </span>
                </span>
                <span
                    class="hidden text-right font-mono text-muted-foreground tabular-nums sm:block"
                >
                    {{
                        crumb.timestamp ? formatTimestamp(crumb.timestamp) : ''
                    }}
                </span>
            </li>
        </ol>

        <button
            v-if="breadcrumbs.length > initial"
            type="button"
            class="mt-3 text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            data-test="breadcrumbs-toggle"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show fewer' : `Show all ${breadcrumbs.length}` }}
        </button>
    </div>
</template>
