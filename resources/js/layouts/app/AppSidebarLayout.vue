<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-h-0 min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />

            <!--
              The page scrolls here rather than in the document, so the
              breadcrumb bar above stays put. A page that manages its own
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
