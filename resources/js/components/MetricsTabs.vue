<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { hosts as metricsHosts, index as metricsIndex } from '@/routes/metrics';

const props = defineProps<{
    teamSlug: string;
    /**
     * The window and project both tabs share, serialised on demand — the
     * same contract as `TracesTabs`: a preset window is relative, so the
     * query is built when the tab is followed, not when the page rendered.
     * Only what both views understand travels; a host or a metric belongs to
     * its own tab.
     */
    query: () => Record<string, string>;
}>();

const page = usePage();

const path = computed(() => page.url.split('?')[0]);

type Tab = {
    id: string;
    label: string;
    href: () => string;
    active: boolean;
};

const tabs = computed<Tab[]>(() => [
    {
        id: 'hosts',
        label: 'Hosts',
        href: () => metricsHosts(props.teamSlug, { query: props.query() }).url,
        active: path.value.endsWith('/metrics/hosts'),
    },
    {
        id: 'explorer',
        label: 'Explorer',
        href: () => metricsIndex(props.teamSlug, { query: props.query() }).url,
        active: !path.value.endsWith('/metrics/hosts'),
    },
]);

/** Follow a tab with a query built now; modified clicks go to the browser. */
function follow(event: MouseEvent, tab: Tab) {
    if (
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    event.preventDefault();
    router.visit(tab.href(), { preserveScroll: true });
}
</script>

<template>
    <nav
        class="flex items-center gap-1 border-b"
        aria-label="Metric views"
        data-test="metrics-tabs"
    >
        <a
            v-for="tab in tabs"
            :key="tab.id"
            :href="tab.href()"
            :class="
                cn(
                    '-mb-px border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    tab.active
                        ? 'border-foreground font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                )
            "
            :aria-current="tab.active ? 'page' : undefined"
            :data-test="`metrics-tab-${tab.id}`"
            @click="follow($event, tab)"
        >
            {{ tab.label }}
        </a>
    </nav>
</template>
