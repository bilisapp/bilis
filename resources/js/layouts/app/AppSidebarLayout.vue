<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { providePageTrail } from '@/composables/usePageTrail';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

/*
 * The trail is drawn by the page's own header (its small uppercase line), so
 * the bar that used to carry it is gone on desktop; the mobile bar keeps it,
 * beside the button that opens the rail.
 */
providePageTrail(() => props.breadcrumbs);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="min-h-0 min-w-0 overflow-x-clip md:bg-card md:ring-1 md:ring-sidebar-border md:peer-data-[variant=inset]:shadow-none"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />

            <!--
              The page scrolls here rather than in the document, so the
              mobile bar above stays put. A page that manages its own
              inner scrolling (the log stream) simply never overflows this.

              `relative` is load-bearing: without a containing block here,
              absolutely positioned descendants resolve against the inset
              instead and escape this element's clipping, which puts the
              document back in charge of scrolling and takes the header
              with it.
            -->
            <div
                class="scrollbar-stream relative flex min-h-0 flex-1 flex-col overflow-y-auto"
            >
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
