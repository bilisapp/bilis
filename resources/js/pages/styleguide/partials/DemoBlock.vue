<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div class="space-y-3 rounded-lg border bg-card p-4">
        <div class="space-y-0.5">
            <h3 class="font-mono text-sm font-medium">{{ title }}</h3>
            <p v-if="description" class="text-xs text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div class="space-y-3">
            <slot />
        </div>
    </div>
</template>
