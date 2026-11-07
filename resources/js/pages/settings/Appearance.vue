<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import FontTabs from '@/components/FontTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Appearance settings" />

    <div class="flex flex-col gap-5">
        <Heading
            variant="small"
            title="Theme"
            description="Light, dark, or follow your system"
        />
        <AppearanceTabs />
    </div>

    <div class="flex flex-col gap-5">
        <Heading
            variant="small"
            title="Font"
            description="Choose the typeface used across the interface and log data"
        />
        <FontTabs />
    </div>
</template>
