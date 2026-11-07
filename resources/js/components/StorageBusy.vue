<script setup lang="ts">
import SurfaceMessage from '@/components/SurfaceMessage.vue';

/**
 * What every read says when ClickHouse did not answer in time. One sentence,
 * worded once: the store was busy, nothing was lost, and trying again is the
 * fix — never the reader's fault and never an error page.
 */
withDefaults(
    defineProps<{
        /** What did not answer, e.g. "Trace storage". */
        store: string;
        inset?: boolean;
        class?: string;
    }>(),
    { inset: true, class: undefined },
);
</script>

<template>
    <SurfaceMessage :inset="inset" :class="$props.class">
        {{ store }} is busy and could not answer in time. Nothing is lost —
        retry in a moment.
    </SurfaceMessage>
</template>
