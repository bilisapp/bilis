<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
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
    <div
        :class="
            cn(
                'flex aspect-square size-8 shrink-0 items-center justify-center',
                tailing && 'mark-live',
            )
        "
    >
        <AppLogoIcon class="size-8" />
    </div>
    <div class="ml-1.5 grid flex-1 text-left">
        <span
            class="truncate text-[15px] leading-tight font-semibold tracking-tight"
            >{{ name }}</span
        >
    </div>
</template>
