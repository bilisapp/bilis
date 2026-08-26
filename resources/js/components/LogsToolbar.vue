<script setup lang="ts">
import { Radio, Search } from '@lucide/vue';
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
    project: string | null;
    service: string | null;
    severity: SeverityLevel[];
    search: string | null;
    range: LogRangePreset;
    from: string;
    to: string;
    liveTail: boolean;
    tailing: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:severity', value: SeverityLevel[]): void;
    (event: 'update:search', value: string | null): void;
    (event: 'update:liveTail', value: boolean): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:window', value: { from: string; to: string }): void;
}>();

const ALL_PROJECTS = '__all__';

const searchTerm = ref(props.search ?? '');
const serviceTerm = ref(props.service ?? '');

watch(
    () => props.search,
    (value) => {
        searchTerm.value = value ?? '';
    },
);

watch(
    () => props.service,
    (value) => {
        serviceTerm.value = value ?? '';
    },
);

const emitSearch = useDebounceFn(() => {
    emit('update:search', searchTerm.value.trim() || null);
}, 350);

const emitService = useDebounceFn(() => {
    emit('update:service', serviceTerm.value.trim() || null);
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
        class="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-sm"
        data-test="logs-toolbar"
    >
        <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground" for="logs-range">
                    Time range
                </Label>
                <Select v-model="rangeValue">
                    <SelectTrigger id="logs-range" class="w-44">
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
            </div>

            <template v-if="range === 'custom'">
                <div class="grid gap-1.5">
                    <Label
                        class="text-xs text-muted-foreground"
                        for="logs-from"
                    >
                        From
                    </Label>
                    <Input
                        id="logs-from"
                        v-model="customFrom"
                        type="datetime-local"
                        class="w-56"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label class="text-xs text-muted-foreground" for="logs-to">
                        To
                    </Label>
                    <Input
                        id="logs-to"
                        v-model="customTo"
                        type="datetime-local"
                        class="w-56"
                    />
                </div>
            </template>

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground" for="logs-project">
                    Project
                </Label>
                <Select v-model="projectValue">
                    <SelectTrigger id="logs-project" class="w-48">
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
            </div>

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground" for="logs-service">
                    Service
                </Label>
                <Input
                    id="logs-service"
                    v-model="serviceTerm"
                    data-test="logs-service"
                    placeholder="All services"
                    class="w-48"
                    @input="emitService"
                />
            </div>

            <div class="grid flex-1 gap-1.5">
                <Label class="text-xs text-muted-foreground" for="logs-search">
                    Search
                </Label>
                <div class="relative">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        id="logs-search"
                        v-model="searchTerm"
                        data-test="logs-search"
                        placeholder="Search log bodies"
                        class="pl-8"
                        @input="emitSearch"
                    />
                </div>
            </div>

            <Button
                type="button"
                data-test="logs-live-tail"
                :variant="liveTail ? 'default' : 'outline'"
                @click="emit('update:liveTail', !liveTail)"
            >
                <Radio :class="cn('size-4', tailing && 'animate-pulse')" />
                {{ liveTail ? 'Live' : 'Live tail' }}
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t pt-3">
            <span class="text-xs text-muted-foreground">Severity</span>
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
