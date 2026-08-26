<script setup lang="ts">
import ComponentsBasics from './ComponentsBasics.vue';
import ComponentsOverlays from './ComponentsOverlays.vue';
import SectionShell from './SectionShell.vue';
</script>

<template>
    <SectionShell
        id="components"
        title="Components"
        description="Every family in resources/js/components/ui, rendered with the props and variants its index.ts actually exports. Nothing here is styled locally: if a demo looks wrong in one of the two modes, the token behind it is wrong."
    >
        <div class="grid items-start gap-4 xl:grid-cols-2">
            <div class="grid items-start gap-4">
                <ComponentsBasics />
            </div>
            <div class="grid items-start gap-4">
                <ComponentsOverlays />
            </div>
        </div>
    </SectionShell>
</template>
