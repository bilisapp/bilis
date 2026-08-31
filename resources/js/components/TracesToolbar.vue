<script setup lang="ts">
import { RotateCcw, TriangleAlert } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { RANGE_PRESETS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { LogProject, LogRangePreset } from '@/types';

const props = defineProps<{
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
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:errors', value: boolean): void;
    (event: 'update:minDuration', value: number | null): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:live', value: boolean): void;
    (event: 'reset'): void;
}>();

const ALL_PROJECTS = '__all__';

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

const projectValue = computed({
    get: () => props.project ?? ALL_PROJECTS,
    set: (value: string) =>
        emit('update:project', value === ALL_PROJECTS ? null : value),
});

const rangeValue = computed({
    get: () => props.range,
    set: (value: LogRangePreset) => emit('update:range', value),
});
</script>

<template>
    <div
        class="flex flex-col rounded-lg border bg-card"
        data-test="traces-toolbar"
    >
        <div class="flex flex-wrap items-center gap-2 p-3">
            <div class="min-w-56 flex-1">
                <Label class="sr-only" for="traces-service">
                    Filter by root service
                </Label>
                <!--
                  A datalist rather than a select: the list is a suggestion
                  drawn from the last week's root services, and the field has
                  to accept a name that is not in it.
                -->
                <Input
                    id="traces-service"
                    v-model="serviceTerm"
                    data-test="traces-service"
                    list="traces-service-options"
                    autocomplete="off"
                    placeholder="Root service…"
                    class="h-10 text-sm"
                    @input="emitService"
                />
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

            <!--
              Errors-only is the filter people reach for first on this page, so
              it gets a control of its own rather than a place in a dropdown.
              It reads sum(ErrorCount) over the whole trace, not the root span.
            -->
            <Button
                type="button"
                size="lg"
                data-test="traces-errors-only"
                :variant="errors ? 'default' : 'outline'"
                :aria-pressed="errors"
                @click="emit('update:errors', !errors)"
            >
                <TriangleAlert class="size-4" />
                Errors only
            </Button>

            <!--
              Live sits beside the filters rather than above the list: it is a
              statement about what the window means — "and everything that has
              arrived since" — not a property of the rows.
            -->
            <Button
                v-if="live !== undefined"
                type="button"
                size="lg"
                data-test="traces-live"
                :variant="live ? 'default' : 'outline'"
                :aria-pressed="live"
                @click="emit('update:live', !live)"
            >
                <span class="relative flex size-2 items-center justify-center">
                    <span
                        v-if="live"
                        class="absolute inline-flex size-2 animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden"
                    />
                    <span
                        :class="
                            cn(
                                'relative inline-flex size-2 rounded-full',
                                live
                                    ? 'bg-current'
                                    : 'border border-current opacity-60',
                                polling && 'opacity-100',
                            )
                        "
                    />
                </span>
                Live
            </Button>
        </div>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-3 py-2"
        >
            <div class="flex items-center gap-2">
                <Label
                    class="text-xs text-muted-foreground"
                    for="traces-project"
                >
                    Project
                </Label>
                <Select v-model="projectValue">
                    <SelectTrigger
                        id="traces-project"
                        size="sm"
                        class="min-w-40"
                        data-test="traces-project"
                    >
                        <SelectValue placeholder="All projects" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_PROJECTS">
                            All projects
                        </SelectItem>
                        <SelectItem
                            v-for="option in projects"
                            :key="option.slug"
                            :value="option.slug"
                        >
                            {{ option.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="flex items-center gap-2">
                <Label
                    class="text-xs text-muted-foreground"
                    for="traces-min-duration"
                >
                    Slower than
                </Label>
                <div class="relative">
                    <Input
                        id="traces-min-duration"
                        v-model="durationTerm"
                        data-test="traces-min-duration"
                        inputmode="numeric"
                        placeholder="0"
                        class="h-8 w-24 pr-8 text-sm"
                        @input="emitDuration"
                    />
                    <span
                        class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-xs text-muted-foreground"
                    >
                        ms
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Label class="text-xs text-muted-foreground" for="traces-range">
                    Window
                </Label>
                <Select v-model="rangeValue">
                    <SelectTrigger
                        id="traces-range"
                        size="sm"
                        class="min-w-40"
                        data-test="traces-range"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="preset in RANGE_PRESETS"
                            :key="preset.value"
                            :value="preset.value"
                        >
                            {{ preset.label }}
                        </SelectItem>
                        <SelectItem value="custom" disabled>
                            Custom range
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Button
                v-if="canReset"
                type="button"
                variant="ghost"
                size="sm"
                class="ml-auto"
                data-test="traces-reset"
                @click="emit('reset')"
            >
                <RotateCcw class="size-4" />
                Reset
            </Button>
        </div>
    </div>
</template>
