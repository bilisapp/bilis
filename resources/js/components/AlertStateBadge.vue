<script setup lang="ts">
import { BellOff, CircleCheck, CircleDashed, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { ALERT_STATE_LABEL } from '@/lib/alerts';
import { cn } from '@/lib/utils';
import type { AlertInstanceState } from '@/types';

/**
 * Where an alert stands, as a pill.
 *
 * State colours are data (specs/alerting.md): firing takes the
 * severity-error token, pending — waiting out its `for` — severity-warn, and
 * OK is neutral chrome. A rule whose last evaluation failed is not a state of
 * the telemetry but of the rule, so it gets no hue at all: an ink fill with a
 * warning glyph, which reads as louder than any neutral and cannot be
 * mistaken for a firing series. Disabled and muted are quiet outlines.
 *
 * Colour never carries the meaning alone: every variant has its word, and
 * firing and pending carry a dot.
 */
const props = withDefaults(
    defineProps<{
        state: AlertInstanceState | 'error' | 'disabled' | 'muted';
        /** A count beside the word: "Firing 3". */
        count?: number | null;
        /** Overrides the word, e.g. "Error" → "Query failed". */
        label?: string;
    }>(),
    { count: null, label: undefined },
);

const word = computed(
    () =>
        props.label ??
        {
            ...ALERT_STATE_LABEL,
            error: 'Error',
            disabled: 'Disabled',
            muted: 'Muted',
        }[props.state],
);

const icon = computed(
    () =>
        ({
            inactive: CircleCheck,
            error: TriangleAlert,
            disabled: CircleDashed,
            muted: BellOff,
        })[props.state as 'inactive' | 'error' | 'disabled' | 'muted'] ?? null,
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-5 shrink-0 items-center gap-1.5 rounded-full px-2 text-[11px] font-medium whitespace-nowrap',
                state === 'firing' &&
                    'bg-severity-error/10 text-severity-error',
                state === 'pending' && 'bg-severity-warn/10 text-severity-warn',
                state === 'error' && 'bg-foreground text-background',
                (state === 'inactive' ||
                    state === 'disabled' ||
                    state === 'muted') &&
                    'text-muted-foreground ring-1 ring-border ring-inset',
            )
        "
        :data-test="`alert-state-${state}`"
    >
        <span
            v-if="state === 'firing' || state === 'pending'"
            :class="
                cn(
                    'size-1.5 rounded-full',
                    state === 'firing'
                        ? 'bg-severity-error'
                        : 'bg-severity-warn',
                )
            "
            aria-hidden="true"
        />
        <component
            :is="icon"
            v-else-if="icon"
            class="size-3"
            aria-hidden="true"
        />
        {{ word }}
        <span v-if="count !== null" class="font-mono tabular-nums">{{
            count
        }}</span>
    </span>
</template>
