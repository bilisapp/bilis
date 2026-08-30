<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import {
    index as tracesIndex,
    latency as tracesLatency,
} from '@/routes/traces';

const props = defineProps<{
    teamSlug: string;
    /**
     * The filters, already serialised. Both tabs carry the same query string so
     * switching views never changes the window or the filters underneath the
     * reader — only the question being asked of them.
     */
    query: Record<string, string>;
}>();

const page = usePage();

/**
 * Matched on the path alone: the query string differs between visits and the
 * tab is a fact about which view is open, not about how it was filtered.
 */
const path = computed(() => page.url.split('?')[0]);

const tabs = computed(() => [
    {
        id: 'traces',
        label: 'Traces',
        href: tracesIndex(props.teamSlug, { query: props.query }).url,
        active: !path.value.endsWith('/traces/latency'),
    },
    {
        id: 'latency',
        label: 'Service latency',
        href: tracesLatency(props.teamSlug, { query: props.query }).url,
        active: path.value.endsWith('/traces/latency'),
    },
]);
</script>

<template>
    <nav
        class="flex items-center gap-1 border-b"
        aria-label="Trace views"
        data-test="traces-tabs"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.id"
            :href="tab.href"
            preserve-scroll
            :class="
                cn(
                    '-mb-px border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    tab.active
                        ? 'border-foreground font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                )
            "
            :aria-current="tab.active ? 'page' : undefined"
            :data-test="`traces-tab-${tab.id}`"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
