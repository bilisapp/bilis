<script setup lang="ts">
import {
    FONT_PREFERENCE_OPTIONS,
    useFontPreference,
} from '@/composables/useFontPreference';

const { font, updateFontPreference } = useFontPreference();
</script>

<template>
    <div
        class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
    >
        <button
            v-for="{ value, label } in FONT_PREFERENCE_OPTIONS"
            :key="value"
            @click="updateFontPreference(value)"
            :class="[
                'rounded-md px-3.5 py-1.5 text-sm transition-colors',
                font === value
                    ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            {{ label }}
        </button>
    </div>
</template>
