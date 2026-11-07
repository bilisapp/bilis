<script setup lang="ts">
import { RotateCcw, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ISSUE_PERIOD_LABEL, ISSUE_SORTS } from '@/lib/issues';
import type { IssuePeriod, IssueSort, LogProject } from '@/types';

/**
 * The issue list's controls, as one row: a search over the exception's type,
 * message and origin, then the window and scope as chips, then the sort.
 *
 * Status is not here: it is the filter triage lives in, so it sits in the page
 * header as segmented tabs. Every control emits; the page owns the query
 * string.
 */
const props = defineProps<{
    projects: LogProject[];
    project: string | null;
    services: string[];
    service: string | null;
    environments: string[];
    environment: string | null;
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
    (event: 'update:period', value: IssuePeriod): void;
    (event: 'update:sort', value: IssueSort): void;
    (event: 'update:query', value: string | null): void;
    (event: 'reset'): void;
}>();

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
 * A picker's options, keeping a selected value listed even when the current
 * window no longer contains it.
 */
function withSelected(options: string[], selected: string | null) {
    const values = new Set(options);

    if (selected) {
        values.add(selected);
    }

    return [...values]
        .sort((a, b) => a.localeCompare(b))
        .map((value) => ({ value, label: value }));
}

const serviceOptions = computed(() =>
    withSelected(props.services, props.service),
);

const environmentOptions = computed(() =>
    withSelected(props.environments, props.environment),
);

const projectOptions = computed(() =>
    props.projects.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
);

const periodOptions = computed(() =>
    props.periods.map((option) => ({
        value: option,
        label: ISSUE_PERIOD_LABEL[option],
    })),
);

const sortOptions = ISSUE_SORTS.map((option) => ({
    value: option.value as string,
    label: option.label,
}));
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-test="issues-toolbar">
        <div class="relative min-w-56 flex-1">
            <Label class="sr-only" for="issues-search">Search issues</Label>
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                id="issues-search"
                v-model="searchTerm"
                data-test="issues-search"
                autocomplete="off"
                placeholder="Search exception type, message or file"
                class="h-9 rounded-lg pr-10 pl-9 text-sm"
                @input="emitQuery"
            />
            <kbd
                class="pointer-events-none absolute top-1/2 right-2.5 kbd -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
                >/</kbd
            >
        </div>

        <FilterSelect
            id="issues-period"
            label="Window"
            data-test="issues-period"
            :model-value="period"
            :options="periodOptions"
            @update:model-value="
                emit('update:period', ($event ?? period) as IssuePeriod)
            "
        />

        <FilterSelect
            v-if="projects.length > 1"
            id="issues-project"
            label="Project"
            all-label="All projects"
            data-test="issues-project"
            :model-value="project"
            :options="projectOptions"
            @update:model-value="emit('update:project', $event)"
        />

        <FilterSelect
            v-if="serviceOptions.length > 0"
            id="issues-service"
            label="Service"
            all-label="All services"
            data-test="issues-service"
            :model-value="service"
            :options="serviceOptions"
            @update:model-value="emit('update:service', $event)"
        />

        <FilterSelect
            v-if="environmentOptions.length > 0"
            id="issues-environment"
            label="Env"
            all-label="All environments"
            data-test="issues-environment"
            :model-value="environment"
            :options="environmentOptions"
            @update:model-value="emit('update:environment', $event)"
        />

        <span
            class="mx-1 hidden h-5 w-px bg-border sm:block"
            aria-hidden="true"
        />

        <FilterSelect
            id="issues-sort"
            label="Sort"
            data-test="issues-sort"
            :model-value="sort"
            :options="sortOptions"
            @update:model-value="
                emit('update:sort', ($event ?? sort) as IssueSort)
            "
        />

        <Button
            v-if="canReset"
            type="button"
            variant="ghost"
            size="sm"
            class="h-9 text-muted-foreground"
            data-test="issues-reset"
            @click="emit('reset')"
        >
            <RotateCcw class="size-3.5" />
            Reset
        </Button>
    </div>
</template>
