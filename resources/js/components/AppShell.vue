<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <!--
      h-svh, not just the provider's own min-h-svh: a min-height leaves the
      shell's height indefinite, so every `flex-1 min-h-0` beneath it collapses
      to auto and inner scroll areas grow the document instead of scrolling.
    -->
    <SidebarProvider v-else :default-open="isOpen" class="h-svh">
        <slot />
    </SidebarProvider>
</template>
