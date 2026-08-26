<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { styleguide } from '@/routes';
import AppComponentsSection from './partials/AppComponentsSection.vue';
import ChartsSection from './partials/ChartsSection.vue';
import ComponentsSection from './partials/ComponentsSection.vue';
import FontsSection from './partials/FontsSection.vue';
import PaletteSection from './partials/PaletteSection.vue';
import SeveritySection from './partials/SeveritySection.vue';
import StyleguideNav from './partials/StyleguideNav.vue';
import TokensSection from './partials/TokensSection.vue';
import TypographySection from './partials/TypographySection.vue';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Styleguide',
                href: styleguide(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Styleguide" />

    <div class="px-4 py-6">
        <header class="mb-2">
            <p class="max-w-3xl text-sm text-muted-foreground">
                The brand palette, the semantic tokens built on top of it, and
                every component the app ships. Flip the appearance toggle in the
                sidebar to check both modes — nothing on this page hardcodes a
                colour outside the brand palette table.
            </p>
        </header>

        <StyleguideNav />

        <div class="space-y-12">
            <PaletteSection />
            <TokensSection />
            <SeveritySection />
            <TypographySection />
            <FontsSection />
            <ComponentsSection />
            <AppComponentsSection />
            <ChartsSection />
        </div>
    </div>
</template>
