<script setup lang="ts" generic="T extends string">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

/**
 * A few mutually exclusive views, one click apart: Unresolved / Resolved /
 * Ignored / All, or a section's own tabs (Traces / Service latency). The
 * chosen segment lifts onto the surface with a hairline — no resting shadow,
 * the chrome stays flat — and may carry a count.
 *
 * An option with an `href` is a page, not a state: it renders as a link and
 * navigates, and `modelValue` simply names the page you are on.
 */
defineProps<{
    modelValue: T;
    options: {
        value: T;
        label: string;
        count?: number | null;
        href?: NonNullable<InertiaLinkProps['href']>;
    }[];
    ariaLabel: string;
    /** `${prefix}-${value}` on each segment, for tests. */
    dataTestPrefix?: string;
}>();

const emit = defineEmits<{
    (event: 'update:modelValue', value: T): void;
}>();

const SEGMENT =
    'inline-flex h-full items-center gap-1.5 rounded-md px-3 text-sm font-medium whitespace-nowrap transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
const ON = 'bg-card text-foreground ring-1 ring-border';
const OFF = 'text-muted-foreground hover:text-foreground';
</script>

<template>
    <div
        class="inline-flex h-9 max-w-full shrink-0 items-center gap-0.5 self-start overflow-x-auto rounded-lg bg-muted p-[3px]"
        :role="options.some((option) => option.href) ? 'navigation' : 'tablist'"
        :aria-label="ariaLabel"
    >
        <template v-for="option in options" :key="option.value">
            <Link
                v-if="option.href"
                :href="option.href"
                :aria-current="modelValue === option.value ? 'page' : undefined"
                :data-test="
                    dataTestPrefix
                        ? `${dataTestPrefix}-${option.value}`
                        : undefined
                "
                :class="cn(SEGMENT, modelValue === option.value ? ON : OFF)"
            >
                {{ option.label }}
                <span
                    v-if="option.count !== undefined && option.count !== null"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                    >{{ option.count }}</span
                >
            </Link>
            <button
                v-else
                type="button"
                role="tab"
                :aria-selected="modelValue === option.value"
                :data-test="
                    dataTestPrefix
                        ? `${dataTestPrefix}-${option.value}`
                        : undefined
                "
                :class="cn(SEGMENT, modelValue === option.value ? ON : OFF)"
                @click="emit('update:modelValue', option.value)"
            >
                {{ option.label }}
                <span
                    v-if="option.count !== undefined && option.count !== null"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                    >{{ option.count }}</span
                >
            </button>
        </template>
    </div>
</template>
