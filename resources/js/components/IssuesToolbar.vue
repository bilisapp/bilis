<script setup lang="ts">
import { RotateCcw, Search } from '@lucide/vue';
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
import {
    ISSUE_PERIOD_LABEL,
    ISSUE_SORTS,
    ISSUE_STATUS_FILTERS,
} from '@/lib/issues';
import { cn } from '@/lib/utils';
import type {
    IssuePeriod,
    IssueSort,
    IssueStatusFilter,
    LogProject,
} from '@/types';

/**
 * The issue list's controls: a search over the exception's type, message and
 * origin; the status tabs; and the window, sort and scope pickers.
 *
 * Status is a row of tabs rather than a dropdown because it is the filter
 * triage lives in — "what is still open" and "what came back" are one click
 * apart. Every control emits; the page owns the query string.
 */
const props = defineProps<{
    projects: LogProject[];
    project: string | null;
    services: string[];
    service: string | null;
    environments: string[];
    environment: string | null;
    status: IssueStatusFilter;
    period: IssuePeriod;
    periods: IssuePeriod[];
    sort: IssueSort;
    query: string | null;
    canReset: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:environment', value: string | null): void;
    (event: 'update:status', value: IssueStatusFilter): void;
    (event: 'update:period', value: IssuePeriod): void;
    (event: 'update:sort', value: IssueSort): void;
    (event: 'update:query', value: string | null): void;
    (event: 'reset'): void;
}>();

const ALL = '__all__';

const searchTerm = ref(props.query ?? '');
const lastEmittedQuery = ref<string | null>(props.query);

watch(
    () => props.query,
    (value) => {
        // Never overwrite what someone is typing with the echo of what they typed.
        const typing =
            typeof document !== 'undefined' &&
            document.activeElement?.id === 'issues-search';

        if (typing && value === lastEmittedQuery.value) {
            return;
        }

        searchTerm.value = value ?? '';
    },
);

const emitQuery = useDebounceFn(() => {
    const value = searchTerm.value.trim() || null;

    lastEmittedQuery.value = value;
    emit('update:query', value);
}, 350);

/**
 * A picker that may be narrowed to "all", keeping a selected value listed
 * even when the current window no longer contains it.
 */
function picker(
    options: () => string[],
    selected: () => string | null,
    update: (value: string | null) => void,
) {
    return {
        options: computed(() => {
            const values = new Set(options());
            const current = selected();

            if (current) {
                values.add(current);
            }

            return [...values].sort((a, b) => a.localeCompare(b));
        }),
        model: computed({
            get: () => selected() ?? ALL,
            set: (value: string) => update(value === ALL ? null : value),
        }),
    };
}

const project = computed({
    get: () => props.project ?? ALL,
    set: (value: string) =>
        emit('update:project', value === ALL ? null : value),
});

const service = picker(
    () => props.services,
    () => props.service,
    (value) => emit('update:service', value),
);

const environment = picker(
    () => props.environments,
    () => props.environment,
    (value) => emit('update:environment', value),
);

const period = computed({
    get: () => props.period,
    set: (value: string) => emit('update:period', value as IssuePeriod),
});

const sort = computed({
    get: () => props.sort,
    set: (value: string) => emit('update:sort', value as IssueSort),
});

const serviceModel = service.model;
const serviceOptions = service.options;
const environmentModel = environment.model;
const environmentOptions = environment.options;
</script>

<template>
    <div
        class="flex flex-col rounded-lg border bg-card"
        data-test="issues-toolbar"
    >
        <div class="flex flex-wrap items-center gap-2 p-3">
            <div class="relative min-w-56 flex-1">
                <Label class="sr-only" for="issues-search">
                    Search issues
                </Label>
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    id="issues-search"
                    v-model="searchTerm"
                    data-test="issues-search"
                    autocomplete="off"
                    placeholder="Exception type, message or file…"
                    class="h-10 pl-9 text-sm"
                    @input="emitQuery"
                />
            </div>

            <div
                class="inline-flex h-10 items-center rounded-md border p-0.5"
                role="tablist"
                aria-label="Status"
            >
                <button
                    v-for="option in ISSUE_STATUS_FILTERS"
                    :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="status === option.value"
                    :data-test="`issues-status-${option.value}`"
                    :class="
                        cn(
                            'h-full rounded-[5px] px-3 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                            status === option.value
                                ? 'bg-accent font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )
                    "
                    @click="emit('update:status', option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-3 py-2"
        >
            <div class="flex items-center gap-2">
                <Label
                    class="text-xs text-muted-foreground"
                    for="issues-period"
                >
                    Window
                </Label>
                <Select v-model="period">
                    <SelectTrigger
                        id="issues-period"
                        size="sm"
                        class="min-w-36"
                        data-test="issues-period"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in periods"
                            :key="option"
                            :value="option"
                        >
                            {{ ISSUE_PERIOD_LABEL[option] }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="flex items-center gap-2">
                <Label class="text-xs text-muted-foreground" for="issues-sort">
                    Sort
                </Label>
                <Select v-model="sort">
                    <SelectTrigger
                        id="issues-sort"
                        size="sm"
                        class="min-w-32"
                        data-test="issues-sort"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in ISSUE_SORTS"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="projects.length > 1" class="flex items-center gap-2">
                <Label
                    class="text-xs text-muted-foreground"
                    for="issues-project"
                >
                    Project
                </Label>
                <Select v-model="project">
                    <SelectTrigger
                        id="issues-project"
                        size="sm"
                        class="min-w-36"
                        data-test="issues-project"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All projects</SelectItem>
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

            <div
                v-if="serviceOptions.length > 0"
                class="flex items-center gap-2"
            >
                <Label
                    class="text-xs text-muted-foreground"
                    for="issues-service"
                >
                    Service
                </Label>
                <Select v-model="serviceModel">
                    <SelectTrigger
                        id="issues-service"
                        size="sm"
                        class="min-w-36"
                        data-test="issues-service"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All services</SelectItem>
                        <SelectItem
                            v-for="option in serviceOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div
                v-if="environmentOptions.length > 0"
                class="flex items-center gap-2"
            >
                <Label
                    class="text-xs text-muted-foreground"
                    for="issues-environment"
                >
                    Environment
                </Label>
                <Select v-model="environmentModel">
                    <SelectTrigger
                        id="issues-environment"
                        size="sm"
                        class="min-w-36"
                        data-test="issues-environment"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All environments</SelectItem>
                        <SelectItem
                            v-for="option in environmentOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
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
                data-test="issues-reset"
                @click="emit('reset')"
            >
                <RotateCcw class="size-3.5" />
                Reset
            </Button>
        </div>
    </div>
</template>
