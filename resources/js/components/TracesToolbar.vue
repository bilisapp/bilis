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

watch(
    () => props.service,
    (value) => {
        serviceTerm.value = value ?? '';
    },
);

watch(
    () => props.minDuration,
    (value) => {
        durationTerm.value = value === null ? '' : String(value);
    },
);

const emitService = useDebounceFn(() => {
    emit('update:service', serviceTerm.value.trim() || null);
}, 350);

const emitDuration = useDebounceFn(() => {
    const parsed = Number.parseInt(durationTerm.value, 10);

    emit(
        'update:minDuration',
        Number.isFinite(parsed) && parsed > 0 ? parsed : null,
    );
}, 350);

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
                <Input
                    id="traces-service"
                    v-model="serviceTerm"
                    data-test="traces-service"
                    placeholder="Root service…"
                    class="h-10 text-sm"
                    @input="emitService"
                />
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
