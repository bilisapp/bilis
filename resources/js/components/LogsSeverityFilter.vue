<script setup lang="ts">
import { SEVERITY_DOT_CLASS, SEVERITY_LEVELS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { SeverityLevel } from '@/types';

/**
 * The severity filter, drawn as the volume chart's legend.
 *
 * The histogram already stacks its bars by severity, so the key to its
 * colours and the control that narrows them are one thing: click a level to
 * keep it, click again to let it go. No level picked means every level, and
 * every swatch is then at full strength; once some are picked, the rest fade.
 */
const props = defineProps<{
    severity: SeverityLevel[];
}>();

const emit = defineEmits<{
    (event: 'update:severity', value: SeverityLevel[]): void;
}>();

const isActive = (level: SeverityLevel) => props.severity.includes(level);

const isShown = (level: SeverityLevel) =>
    props.severity.length === 0 || isActive(level);

const toggle = (level: SeverityLevel) => {
    emit(
        'update:severity',
        isActive(level)
            ? props.severity.filter((value) => value !== level)
            : [...props.severity, level],
    );
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-1 gap-y-1"
        role="group"
        aria-label="Severity"
        data-test="logs-severity"
    >
        <button
            v-for="level in SEVERITY_LEVELS"
            :key="level"
            type="button"
            :data-test="`logs-severity-${level}`"
            :aria-pressed="isActive(level)"
            :class="
                cn(
                    'inline-flex h-6 items-center gap-1.5 rounded-md px-1.5 text-xs capitalize transition-colors outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    isActive(level)
                        ? 'bg-muted font-medium text-foreground'
                        : isShown(level)
                          ? 'text-foreground/80'
                          : 'text-muted-foreground/70',
                )
            "
            @click="toggle(level)"
        >
            <span
                :class="
                    cn(
                        'size-2 rounded-[2px] transition-opacity',
                        SEVERITY_DOT_CLASS[level],
                        isShown(level) ? 'opacity-100' : 'opacity-30',
                    )
                "
                aria-hidden="true"
            />
            {{ level }}
        </button>
    </div>
</template>
