<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import {
    index as tracesIndex,
    latency as tracesLatency,
} from '@/routes/traces';

const props = defineProps<{
    teamSlug: string;
    /**
     * The filters, serialised on demand. Both tabs carry the same query string
     * so switching views never changes the window or the filters underneath
     * the reader — only the question being asked of them.
     *
     * A function rather than a value because a preset window is relative:
     * "last hour" means the hour ending *now*, and a query string built when
     * the page rendered would hand the other tab a window that ended minutes
     * ago. The href drawn on the link is refreshed on render for the sake of
     * copy-link and open-in-new-tab; the click itself asks again.
     */
    query: () => Record<string, string>;
}>();

const page = usePage();

/**
 * Matched on the path alone: the query string differs between visits and the
 * tab is a fact about which view is open, not about how it was filtered.
 */
const path = computed(() => page.url.split('?')[0]);

type Tab = {
    id: string;
    label: string;
    href: () => string;
    active: boolean;
};

const tabs = computed<Tab[]>(() => [
    {
        id: 'traces',
        label: 'Traces',
        href: () => tracesIndex(props.teamSlug, { query: props.query() }).url,
        active: !path.value.endsWith('/traces/latency'),
    },
    {
        id: 'latency',
        label: 'Service latency',
        href: () => tracesLatency(props.teamSlug, { query: props.query() }).url,
        active: path.value.endsWith('/traces/latency'),
    },
]);

/**
 * Follow a tab with a query string built at this instant.
 *
 * A plain anchor with its own handler instead of `<Link>`: the link component
 * visits the href it was rendered with, which is exactly the stale window this
 * guards against. Modified clicks — new tab, new window — are left to the
 * browser and the rendered href, which is as fresh as the last render.
 */
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
        aria-label="Trace views"
        data-test="traces-tabs"
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
            :data-test="`traces-tab-${tab.id}`"
            @click="follow($event, tab)"
        >
            {{ tab.label }}
        </a>
    </nav>
</template>
