<script setup lang="ts">
import { STYLEGUIDE_SECTIONS } from '@/pages/styleguide/data';
</script>

<template>
    <nav
        aria-label="Styleguide sections"
        class="sticky top-0 z-10 -mx-4 mb-6 border-b bg-background/85 px-4 py-2 backdrop-blur"
    >
        <ul class="flex flex-wrap items-center gap-1">
            <li v-for="section in STYLEGUIDE_SECTIONS" :key="section.id">
                <a
                    :href="`#${section.id}`"
                    class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    {{ section.title }}
                </a>
            </li>
        </ul>
    </nav>
</template>
