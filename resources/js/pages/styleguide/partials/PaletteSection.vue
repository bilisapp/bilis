<script setup lang="ts">
import { BRAND_SWATCHES } from '@/pages/styleguide/data';
import SectionShell from './SectionShell.vue';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SectionShell
        id="palette"
        title="Brand palette"
        description="Nine fixed colours pulled from the mid-century abstract-stripes artwork Bilis is named after. These are static Tailwind utilities: they do not change between light and dark mode, so reach for the semantic tokens below for anything that has to invert."
    >
        <SwatchGrid :swatches="BRAND_SWATCHES" />
    </SectionShell>
</template>
