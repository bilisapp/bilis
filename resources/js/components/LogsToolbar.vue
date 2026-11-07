<script setup lang="ts">
import { Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/FilterSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RANGE_PRESETS } from '@/lib/logs';
import type { LogProject, LogRangePreset } from '@/types';

/**
 * The log viewer's question, as one row: a search over the bodies, then the
 * scope and the window as chips. A custom window opens its two inputs in
 * place. Live tail, step back and reset act on the whole page and live in its
 * header; severity is the volume chart's legend ({@link LogsSeverityFilter}).
 */
const props = defineProps<{
    projects: LogProject[];
    /** The service names seen for the projects in scope; deferred, so it can arrive late. */
    services?: string[];
    project: string | null;
    service: string | null;
    search: string | null;
    range: LogRangePreset;
    from: string;
    to: string;
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:search', value: string | null): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:window', value: { from: string; to: string }): void;
}>();

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

const projectOptions = computed(() =>
    props.projects.map((item) => ({ value: item.slug, label: item.name })),
);

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

const rangeOptions = [
    ...RANGE_PRESETS.map((preset) => ({
        value: preset.value as string,
        label: preset.label,
    })),
    { value: 'custom', label: 'Custom range' },
];

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
    <div class="flex flex-wrap items-center gap-2" data-test="logs-toolbar">
        <div class="relative min-w-64 flex-1">
            <Label class="sr-only" for="logs-search">Search log bodies</Label>
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id="logs-search"
                v-model="searchTerm"
                data-test="logs-search"
                placeholder="Search log bodies"
                class="h-9 rounded-lg pr-10 pl-9 text-sm"
                @input="emitSearch"
            />
            <kbd
                class="pointer-events-none absolute top-1/2 right-2.5 kbd -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
                >/</kbd
            >
        </div>

        <FilterSelect
            id="logs-project"
            label="Project"
            all-label="All projects"
            data-test="logs-project"
            :model-value="project"
            :options="projectOptions"
            @update:model-value="emit('update:project', $event)"
        />

        <FilterSelect
            id="logs-service"
            label="Service"
            all-label="All services"
            data-test="logs-service"
            :model-value="service"
            :options="
                serviceOptions.map((name) => ({ value: name, label: name }))
            "
            @update:model-value="emit('update:service', $event)"
        />

        <FilterSelect
            id="logs-range"
            label="Window"
            data-test="logs-range"
            :model-value="range"
            :options="rangeOptions"
            @update:model-value="
                emit('update:range', ($event ?? range) as LogRangePreset)
            "
        />

        <template v-if="range === 'custom'">
            <Label class="sr-only" for="logs-from">From</Label>
            <Input
                id="logs-from"
                v-model="customFrom"
                type="datetime-local"
                class="h-9 w-52 rounded-lg"
            />
            <span class="text-xs text-muted-foreground">to</span>
            <Label class="sr-only" for="logs-to">To</Label>
            <Input
                id="logs-to"
                v-model="customTo"
                type="datetime-local"
                class="h-9 w-52 rounded-lg"
            />
        </template>
    </div>
</template>
