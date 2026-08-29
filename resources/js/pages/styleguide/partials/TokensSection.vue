<script setup lang="ts">
import { TOKEN_SWATCHES } from '@/pages/styleguide/data';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SwatchGrid :swatches="TOKEN_SWATCHES" />
</template>
