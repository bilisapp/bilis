<script setup lang="ts">
import {
    FONT_PREFERENCE_OPTIONS,
    useFontPreference,
} from '@/composables/useFontPreference';

const { font, updateFontPreference } = useFontPreference();
</script>

<template>
    <div
        class="inline-flex h-9 items-center gap-0.5 self-start rounded-lg bg-muted p-[3px]"
    >
        <button
            v-for="{ value, label } in FONT_PREFERENCE_OPTIONS"
            :key="value"
            @click="updateFontPreference(value)"
            :class="[
                'flex h-full items-center rounded-md px-3 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                font === value
                    ? 'bg-card font-medium text-foreground ring-1 ring-border'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
        >
            {{ label }}
        </button>
    </div>
</template>
