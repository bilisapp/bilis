<script setup lang="ts">
import { SURFACE_SWATCHES } from '@/pages/styleguide/data';
import SwatchGrid from './SwatchGrid.vue';
</script>

<template>
    <SwatchGrid :swatches="SURFACE_SWATCHES" />
</template>
