<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div class="flex aspect-square size-8 shrink-0 items-center justify-center">
        <AppLogoIcon class="size-8" />
    </div>
    <div class="ml-1.5 grid flex-1 text-left">
        <span
            class="truncate text-[15px] leading-tight font-semibold tracking-tight"
            >{{ name }}</span
        >
    </div>
</template>
