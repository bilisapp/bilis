<script setup lang="ts">
import { RotateCcw, Search, TriangleAlert } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RANGE_PRESETS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { LogProject, LogRangePreset } from '@/types';

/**
 * The traces surface's controls, as one row: the root-service field, then the
 * window, scope and slowness as chips, the errors-only toggle, and — on the
 * list only — Live at the far end. Every control emits; the page owns the
 * query string.
 */
const props = withDefaults(
    defineProps<{
        projects: LogProject[];
        project: string | null;
        /**
         * The root services seen for the projects in scope; deferred, so it can
         * arrive late. Suggestions, not a constraint: the field stays free text,
         * because the latency tab matches any span's service and a service that
         * never roots a trace is still worth asking about.
         */
        services?: string[];
        /** Matches the trace's root service — all the summary table knows. */
        service: string | null;
        errors: boolean;
        /** Milliseconds. */
        minDuration: number | null;
        range: LogRangePreset;
        /** The filters are not already at their default state. */
        canReset: boolean;
        /**
         * Whether the list is polling for traces that arrived after it loaded.
         * Absent on views that have nothing to tail — the latency tab renders the
         * same toolbar and must not offer a control that would do nothing.
         */
        live?: boolean;
        /** A poll is in flight right now, which the dot reports. */
        polling?: boolean;
        /** `/` focuses the service field on this page, so the field says so. */
        slashHint?: boolean;
    }>(),
    {
        services: undefined,
        // An explicit `undefined`: Vue casts an absent boolean prop to
        // `false`, which would draw Live on the latency tab after all.
        live: undefined,
        polling: false,
        slashHint: false,
    },
);

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:errors', value: boolean): void;
    (event: 'update:minDuration', value: number | null): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:live', value: boolean): void;
    (event: 'reset'): void;
}>();

const serviceTerm = ref(props.service ?? '');
const durationTerm = ref(
    props.minDuration === null ? '' : String(props.minDuration),
);

/**
 * The last values this toolbar sent up, so their echo can be told apart from a
 * change that came from somewhere else.
 *
 * Each field is debounced, so a reader typing "checkout" sends "check" first
 * and keeps typing while that round trip is in flight. When it lands, the prop
 * becomes "check" — and syncing the field to the prop would delete the "out"
 * the reader typed in the meantime. The echo of our own emit is therefore
 * ignored; a value that is *not* our echo (a reset, the back button, a shared
 * link) is a real change and is written into the field. Once the field has
 * lost focus there is nothing to protect and the prop always wins.
 */
const lastEmittedService = ref<string | null>(props.service);
const lastEmittedDuration = ref<number | null>(props.minDuration);

const isFocused = (id: string) =>
    typeof document !== 'undefined' && document.activeElement?.id === id;

watch(
    () => props.service,
    (value) => {
        if (isFocused('traces-service') && value === lastEmittedService.value) {
            return;
        }

        serviceTerm.value = value ?? '';
    },
);

watch(
    () => props.minDuration,
    (value) => {
        if (
            isFocused('traces-min-duration') &&
            value === lastEmittedDuration.value
        ) {
            return;
        }

        durationTerm.value = value === null ? '' : String(value);
    },
);

const emitService = useDebounceFn(() => {
    const value = serviceTerm.value.trim() || null;

    lastEmittedService.value = value;
    emit('update:service', value);
}, 350);

const emitDuration = useDebounceFn(() => {
    const parsed = Number.parseInt(durationTerm.value, 10);
    const value = Number.isFinite(parsed) && parsed > 0 ? parsed : null;

    lastEmittedDuration.value = value;
    emit('update:minDuration', value);
}, 350);

/**
 * The options the picker offers.
 *
 * A service the reader arrived with — from a shared link, or one that has gone
 * quiet since — is kept in the list, so the control never suggests less than
 * it is showing.
 */
const serviceOptions = computed(() => {
    const names = new Set(props.services ?? []);

    if (props.service) {
        names.add(props.service);
    }

    return [...names].sort((a, b) => a.localeCompare(b));
});

const projectOptions = computed(() =>
    props.projects.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
);

/**
 * The window presets. "Custom range" is listed only while it is the truth —
 * a zoomed histogram bar — so the chip can name it; choosing it does nothing.
 */
const rangeOptions = computed(() => [
    ...RANGE_PRESETS.map((preset) => ({
        value: preset.value as string,
        label: preset.label,
    })),
    ...(props.range === 'custom'
        ? [{ value: 'custom', label: 'Custom range' }]
        : []),
]);

function onRange(value: string | null) {
    if (value === null || value === 'custom') {
        return;
    }

    emit('update:range', value as LogRangePreset);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-test="traces-toolbar">
        <div class="relative min-w-56 flex-1">
            <Label class="sr-only" for="traces-service">
                Filter by root service
            </Label>
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <!--
              A datalist rather than a select: the list is a suggestion drawn
              from the last week's root services, and the field has to accept a
              name that is not in it.
            -->
            <Input
                id="traces-service"
                v-model="serviceTerm"
                data-test="traces-service"
                list="traces-service-options"
                autocomplete="off"
                placeholder="Root service…"
                :class="
                    cn('h-9 rounded-lg pl-9 text-sm', slashHint ? 'pr-10' : '')
                "
                @input="emitService"
            />
            <kbd
                v-if="slashHint"
                class="pointer-events-none absolute top-1/2 right-2.5 kbd -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
                >/</kbd
            >
            <datalist
                id="traces-service-options"
                data-test="traces-service-options"
            >
                <option
                    v-for="name in serviceOptions"
                    :key="name"
                    :value="name"
                />
            </datalist>
        </div>

        <FilterSelect
            id="traces-range"
            label="Window"
            data-test="traces-range"
            :model-value="range"
            :options="rangeOptions"
            @update:model-value="onRange"
        />

        <FilterSelect
            id="traces-project"
            label="Project"
            all-label="All projects"
            data-test="traces-project"
            :model-value="project"
            :options="projectOptions"
            @update:model-value="emit('update:project', $event)"
        />

        <!--
          A chip with a field inside: the label reads as a sentence with the
          number — "Slower than 250 ms" — the way the select chips do.
        -->
        <label
            for="traces-min-duration"
            class="inline-flex h-9 cursor-text items-center gap-1.5 rounded-lg border px-3 text-sm transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
        >
            <span class="text-muted-foreground">Slower than</span>
            <input
                id="traces-min-duration"
                v-model="durationTerm"
                data-test="traces-min-duration"
                inputmode="numeric"
                placeholder="0"
                class="w-12 min-w-0 bg-transparent font-mono font-medium text-foreground tabular-nums outline-none placeholder:text-muted-foreground"
                @input="emitDuration"
            />
            <span class="text-muted-foreground">ms</span>
        </label>

        <!--
          Errors-only is the filter people reach for first on this page, so it
          gets a control of its own rather than a place in a dropdown. It reads
          sum(ErrorCount) over the whole trace, not the root span.
        -->
        <button
            type="button"
            data-test="traces-errors-only"
            :aria-pressed="errors"
            :class="
                cn(
                    'inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    errors
                        ? 'bg-muted font-medium text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                )
            "
            @click="emit('update:errors', !errors)"
        >
            <TriangleAlert class="size-3.5" />
            Errors only
        </button>

        <Button
            v-if="canReset"
            type="button"
            variant="ghost"
            size="sm"
            class="h-9 text-muted-foreground"
            data-test="traces-reset"
            @click="emit('reset')"
        >
            <RotateCcw class="size-3.5" />
            Reset
        </Button>

        <!--
          Live sits with the filters rather than above the list: it is a
          statement about what the window means — "and everything that has
          arrived since" — not a property of the rows. Dressed like the log
          viewer's: ink while it is an offer, quiet with a pulse while on.
        -->
        <Button
            v-if="live !== undefined"
            type="button"
            class="ml-auto h-9"
            data-test="traces-live"
            :variant="live ? 'outline' : 'default'"
            :aria-pressed="live"
            @click="emit('update:live', !live)"
        >
            <span class="relative flex size-2 items-center justify-center">
                <span
                    v-if="live"
                    class="absolute inline-flex size-2 animate-ping rounded-full bg-severity-info opacity-60 motion-reduce:hidden"
                />
                <span
                    :class="
                        cn(
                            'relative inline-flex size-2 rounded-full',
                            live
                                ? 'bg-severity-info'
                                : 'ring-[1.5px] ring-current ring-inset',
                            polling && 'opacity-100',
                        )
                    "
                />
            </span>
            {{ live ? 'Live' : 'Go live' }}
        </Button>
    </div>
</template>
