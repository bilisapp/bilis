<script setup lang="ts">
/**
 * A keyboard shortcut as it appears in a list's footer: the key(s), then
 * what they do. Only print keys a page actually binds — `useListCursor`
 * answers j/k, Enter/o, y, / and ?.
 */
defineProps<{
    keys: string[];
    label: string;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <kbd v-for="key in keys" :key="key" class="kbd text-foreground/70">
            {{ key }}
        </kbd>
        <span>{{ label }}</span>
    </span>
</template>
