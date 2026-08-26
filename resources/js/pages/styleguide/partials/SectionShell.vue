<script setup lang="ts">
defineProps<{
    id: string;
    title: string;
    description: string;
}>();
</script>

<template>
    <section :id="id" class="scroll-mt-20 space-y-6">
        <header class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
            <p class="max-w-3xl text-sm text-muted-foreground">
                {{ description }}
            </p>
        </header>

        <slot />
    </section>
</template>
