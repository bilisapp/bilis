<script setup lang="ts">
import {
    SPAN_NAMING_OPTIONS,
    useSpanNaming,
} from '@/composables/useSpanNaming';
import { cn } from '@/lib/utils';

defineProps<{
    /** Side-panel width: labels give way to a single-letter mark. */
    compact?: boolean;
}>();

const { naming, setNaming } = useSpanNaming();
</script>

<template>
    <!--
      A segmented control rather than a checkbox: the two states are two ways of
      reading the same data, neither of them an "off". Naming it Smart/Raw says
      which one is a derivation without needing a sentence.
    -->
    <div
        class="flex items-center rounded-md border p-0.5"
        role="group"
        aria-label="Span naming"
        data-test="span-naming-toggle"
    >
        <button
            v-for="option in SPAN_NAMING_OPTIONS"
            :key="option.value"
            type="button"
            :class="
                cn(
                    'rounded-sm px-1.5 py-0.5 text-xs transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    naming === option.value
                        ? 'bg-accent font-medium text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            :title="option.hint"
            :aria-pressed="naming === option.value"
            :data-test="`span-naming-${option.value}`"
            @click="setNaming(option.value)"
        >
            {{ compact ? option.label.charAt(0) : option.label }}
        </button>
    </div>
</template>
