<script setup lang="ts">
import { TOKEN_SWATCHES } from '@/pages/styleguide/data';
import SectionShell from './SectionShell.vue';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SectionShell
        id="tokens"
        title="Semantic tokens"
        description="Every swatch below is painted with its own utility, so this grid is also the proof that the theme inverts correctly. Light mode is navy on cream: navy primary, greige surfaces, espresso ink. Dark mode flips to gold on espresso: gold primary and focus rings, espresso surfaces, cream ink. Toggle the appearance switch and watch this section swap."
    >
        <SwatchGrid :swatches="TOKEN_SWATCHES" />
    </SectionShell>
</template>
