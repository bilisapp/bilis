<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import { useTailStatus } from '@/composables/useTailStatus';
import { cn } from '@/lib/utils';

const name = usePage().props.name;

/**
 * While the log stream is running, the mark's tail lights in sequence. It is
 * the only continuous motion in the chrome, and it means exactly one thing.
 */
const { tailing } = useTailStatus();
</script>

<template>
    <!--
      The mark carries the name on its own, at a size where it can. The
      wordmark that used to sit beside a 32px square only made the square
      smaller; the landscape mark uses the whole width of the rail instead.
      The name stays in the accessibility tree, where it is still needed.
    -->
    <div
        :class="cn('flex min-w-0 flex-1 items-center', tailing && 'mark-live')"
    >
        <AppLogoMark
            class="h-8 w-auto max-w-full group-data-[collapsible=icon]:hidden"
        />
        <AppLogoIcon
            class="hidden size-5 shrink-0 group-data-[collapsible=icon]:block"
        />
        <span class="sr-only">{{ name }}</span>
    </div>
</template>
