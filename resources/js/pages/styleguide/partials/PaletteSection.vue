<script setup lang="ts">
import { SURFACE_SWATCHES } from '@/pages/styleguide/data';
import SectionShell from './SectionShell.vue';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SectionShell
        id="surfaces"
        title="Neutral ladder"
        description="Bilis spends colour on log severity and nowhere else. Everything that is not a severity — every surface, border, button, focus ring and piece of type — is cut from this achromatic ladder. The steps all carry the same faint cool cast so they read as one material rather than as a pile of unrelated greys, and the discipline is what leaves the data — the severity ramp and the chart series, both drawn from the mark's tail — as the only hues a reader can find on the screen. Ordered here from the deepest rail to full ink; every step inverts with the mode."
    >
        <SwatchGrid :swatches="SURFACE_SWATCHES" />
    </SectionShell>
</template>
