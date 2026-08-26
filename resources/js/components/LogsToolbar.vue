<script setup lang="ts">
import { RotateCcw, Search, Undo2 } from '@lucide/vue';
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
import { RANGE_PRESETS, SEVERITY_DOT_CLASS, SEVERITY_LEVELS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import type { LogProject, LogRangePreset, SeverityLevel } from '@/types';

const props = defineProps<{
    projects: LogProject[];
    /** The service names seen for the projects in scope; deferred, so it can arrive late. */
    services?: string[];
    project: string | null;
    service: string | null;
    severity: SeverityLevel[];
    search: string | null;
    range: LogRangePreset;
    from: string;
    to: string;
    liveTail: boolean;
    tailing: boolean;
    /** There is an earlier filter state to return to. */
    canStepBack: boolean;
    /** The filters are not already at their default state. */
    canReset: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:severity', value: SeverityLevel[]): void;
    (event: 'update:search', value: string | null): void;
    (event: 'update:liveTail', value: boolean): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:window', value: { from: string; to: string }): void;
    /** Undo the last filter change. */
    (event: 'stepBack'): void;
    /** Return every filter to its default. */
    (event: 'reset'): void;
}>();

const ALL_PROJECTS = '__all__';
const ALL_SERVICES = '__all__';

const searchTerm = ref(props.search ?? '');

watch(
    () => props.search,
    (value) => {
        searchTerm.value = value ?? '';
    },
);

const emitSearch = useDebounceFn(() => {
    emit('update:search', searchTerm.value.trim() || null);
}, 350);

const projectValue = computed({
    get: () => props.project ?? ALL_PROJECTS,
    set: (value: string) =>
        emit('update:project', value === ALL_PROJECTS ? null : value),
});

/**
 * The options the picker offers.
 *
 * A service the reader arrived with — from a shared link, or one that has gone
 * quiet since — is kept in the list, so the control never shows a value it
 * cannot explain.
 */
const serviceOptions = computed(() => {
    const names = new Set(props.services ?? []);

    if (props.service) {
        names.add(props.service);
    }

    return [...names].sort((a, b) => a.localeCompare(b));
});

const serviceValue = computed({
    get: () => props.service ?? ALL_SERVICES,
    set: (value: string) =>
        emit('update:service', value === ALL_SERVICES ? null : value),
});

const rangeValue = computed({
    get: () => props.range,
    set: (value: LogRangePreset) => emit('update:range', value),
});

const customFrom = computed({
    get: () => toLocalInput(props.from),
    set: (value: string) =>
        emit('update:window', {
            from: fromLocalInput(value, props.from),
            to: props.to,
        }),
});

const customTo = computed({
    get: () => toLocalInput(props.to),
    set: (value: string) =>
        emit('update:window', {
            from: props.from,
            to: fromLocalInput(value, props.to),
        }),
});

const isSeverityActive = (level: SeverityLevel) =>
    props.severity.includes(level);

const toggleSeverity = (level: SeverityLevel) => {
    emit(
        'update:severity',
        isSeverityActive(level)
            ? props.severity.filter((value) => value !== level)
            : [...props.severity, level],
    );
};

function toLocalInput(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const offset = date.getTimezoneOffset() * 60_000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function fromLocalInput(value: string, fallback: string): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? fallback : date.toISOString();
}
</script>

<template>
    <div
        class="flex flex-col rounded-lg border bg-card"
        data-test="logs-toolbar"
    >
        <!--
          Tier 1 — the question being asked. Search is the primary action on
          this page, so it gets the full width and the taller control; live
          tail sits beside it because it is the other way logs arrive.
        -->
        <div class="flex flex-wrap items-center gap-2 p-3">
            <div class="relative min-w-64 flex-1">
                <Label class="sr-only" for="logs-search">
                    Search log bodies
                </Label>
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    id="logs-search"
                    v-model="searchTerm"
                    data-test="logs-search"
                    placeholder="Search log bodies…"
                    class="h-10 pl-9 text-sm"
                    @input="emitSearch"
                />
            </div>

            <Button
                type="button"
                size="lg"
                data-test="logs-live-tail"
                :variant="liveTail ? 'default' : 'outline'"
                :aria-pressed="liveTail"
                @click="emit('update:liveTail', !liveTail)"
            >
                <span class="relative flex size-2 items-center justify-center">
                    <span
                        v-if="liveTail"
                        class="absolute inline-flex size-2 animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden"
                    />
                    <span
                        :class="
                            cn(
                                'relative inline-flex size-2 rounded-full',
                                liveTail
                                    ? 'bg-current'
                                    : 'border border-current opacity-60',
                                tailing && 'opacity-100',
                            )
                        "
                    />
                </span>
                Live
            </Button>
        </div>

        <!--
          Tier 2 — what you are looking at, and when. Two groups, one hairline
          between them, so scope and window never read as one flat row of six.
        -->
        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t px-3 py-2"
        >
            <div
                class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto"
            >
                <Label
                    class="shrink-0 text-xs font-medium text-muted-foreground"
                    for="logs-project"
                >
                    Scope
                </Label>
                <Select v-model="projectValue">
                    <SelectTrigger
                        id="logs-project"
                        class="h-8 min-w-40 flex-1 sm:w-44 sm:flex-none"
                    >
                        <SelectValue placeholder="All projects" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_PROJECTS">
                            All projects
                        </SelectItem>
                        <SelectItem
                            v-for="item in projects"
                            :key="item.slug"
                            :value="item.slug"
                        >
                            {{ item.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Label class="sr-only" for="logs-service">Service</Label>
                <Select v-model="serviceValue">
                    <SelectTrigger
                        id="logs-service"
                        data-test="logs-service"
                        class="h-8 min-w-40 flex-1 sm:w-44 sm:flex-none"
                    >
                        <SelectValue placeholder="All services" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_SERVICES">
                            All services
                        </SelectItem>
                        <SelectItem
                            v-for="name in serviceOptions"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                class="mx-1 hidden h-5 w-px shrink-0 bg-border sm:block"
                aria-hidden="true"
            />

            <div
                class="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto"
            >
                <Label
                    class="shrink-0 text-xs font-medium text-muted-foreground"
                    for="logs-range"
                >
                    Window
                </Label>
                <Select v-model="rangeValue">
                    <SelectTrigger
                        id="logs-range"
                        class="h-8 min-w-40 flex-1 sm:w-40 sm:flex-none"
                    >
                        <SelectValue placeholder="Time range" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="preset in RANGE_PRESETS"
                            :key="preset.value"
                            :value="preset.value"
                        >
                            {{ preset.label }}
                        </SelectItem>
                        <SelectItem value="custom">Custom range</SelectItem>
                    </SelectContent>
                </Select>

                <template v-if="range === 'custom'">
                    <Label class="sr-only" for="logs-from">From</Label>
                    <Input
                        id="logs-from"
                        v-model="customFrom"
                        type="datetime-local"
                        class="h-8 min-w-52 flex-1 sm:w-52 sm:flex-none"
                    />
                    <span class="text-xs text-muted-foreground">to</span>
                    <Label class="sr-only" for="logs-to">To</Label>
                    <Input
                        id="logs-to"
                        v-model="customTo"
                        type="datetime-local"
                        class="h-8 min-w-52 flex-1 sm:w-52 sm:flex-none"
                    />
                </template>
            </div>

            <!--
              The history controls act on the whole filter set, so they close
              the tier rather than sitting inside either group. Both stay
              visible and go disabled: a control that disappears once it has
              nothing to do is a control nobody learns is there.
            -->
            <div class="flex items-center gap-1 sm:ml-auto">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="logs-step-back"
                    :disabled="!canStepBack"
                    @click="emit('stepBack')"
                >
                    <Undo2 class="size-4" />
                    Step back
                </Button>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="logs-reset"
                    :disabled="!canReset"
                    @click="emit('reset')"
                >
                    <RotateCcw class="size-4" />
                    Reset
                </Button>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t px-3 py-2"
        >
            <span class="text-xs font-medium text-muted-foreground"
                >Severity</span
            >
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="level in SEVERITY_LEVELS"
                    :key="level"
                    type="button"
                    :data-test="`logs-severity-${level}`"
                    :aria-pressed="isSeverityActive(level)"
                    :class="
                        cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs capitalize transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            isSeverityActive(level)
                                ? 'border-foreground/25 bg-secondary font-semibold text-secondary-foreground shadow-xs'
                                : 'border-border bg-background font-medium text-muted-foreground hover:border-foreground/20 hover:text-foreground',
                        )
                    "
                    @click="toggleSeverity(level)"
                >
                    <span
                        :class="
                            cn(
                                'size-2 rounded-full transition-opacity',
                                SEVERITY_DOT_CLASS[level],
                                isSeverityActive(level)
                                    ? 'opacity-100'
                                    : 'opacity-40',
                            )
                        "
                    />
                    {{ level }}
                </button>
            </div>

            <span
                v-if="severity.length > 0"
                class="ml-auto text-xs text-muted-foreground"
            >
                Showing {{ severity.length }} of
                {{ SEVERITY_LEVELS.length }} levels
            </span>
            <span v-else class="ml-auto text-xs text-muted-foreground">
                All severities
            </span>
        </div>
    </div>
</template>
