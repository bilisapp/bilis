<script setup lang="ts">
import { Plus, RotateCcw, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import FilterSelect from '@/components/FilterSelect.vue';
import MetricsLiveToggle from '@/components/MetricsLiveToggle.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { RANGE_PRESETS } from '@/lib/logs';
import {
    catalogByType,
    MAX_METRIC_FILTERS,
    METRIC_AGGREGATIONS,
} from '@/lib/metrics';
import type {
    LogProject,
    LogRangePreset,
    MetricAggregation,
    MetricAttributes,
    MetricCatalog,
    MetricSeriesKind,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    project: string | null;
    /** Deferred: which metrics exist. The picker waits for it. */
    catalog?: MetricCatalog;
    metric: string | null;
    service: string | null;
    /** Deferred: the selected metric's attribute keys and common values. */
    attributes?: MetricAttributes;
    where: Record<string, string>;
    groupBy: string | null;
    agg: MetricAggregation;
    /**
     * What the chart is drawing. Aggregation only means something for a level,
     * so the control is offered only once the series says it is one.
     */
    kind?: MetricSeriesKind | null;
    range: LogRangePreset;
    /** The filters are not already at their default state. */
    canReset: boolean;
    /** Whether the charts refresh as new points arrive. */
    live?: boolean;
    /** Whether the window ends at now, which live needs. */
    liveAvailable?: boolean;
    /** A live refresh is in flight. */
    refreshing?: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:project', value: string | null): void;
    (event: 'update:metric', value: string | null): void;
    (event: 'update:service', value: string | null): void;
    (event: 'update:where', value: Record<string, string>): void;
    (event: 'update:groupBy', value: string | null): void;
    (event: 'update:agg', value: MetricAggregation): void;
    (event: 'update:range', value: LogRangePreset): void;
    (event: 'update:live', value: boolean): void;
    (event: 'reset'): void;
}>();

const serviceTerm = ref(props.service ?? '');

/**
 * The last service this toolbar sent up — the same echo guard the traces
 * toolbar keeps, so a debounced round trip landing mid-word does not delete
 * what the reader typed since.
 */
const lastEmittedService = ref<string | null>(props.service);

const isFocused = (id: string) =>
    typeof document !== 'undefined' && document.activeElement?.id === id;

watch(
    () => props.service,
    (value) => {
        if (
            isFocused('metrics-service') &&
            value === lastEmittedService.value
        ) {
            return;
        }

        serviceTerm.value = value ?? '';
    },
);

const emitService = useDebounceFn(() => {
    const value = serviceTerm.value.trim() || null;

    lastEmittedService.value = value;
    emit('update:service', value);
}, 350);

const catalogGroups = computed(() =>
    catalogByType(props.catalog?.metrics ?? []),
);

const selectedEntry = computed(
    () =>
        props.catalog?.metrics.find((entry) => entry.name === props.metric) ??
        null,
);

/**
 * A metric the reader arrived with — a shared link to a chart that has since
 * gone quiet — stays pickable, so the control never shows less than the page
 * is charting.
 */
const orphanMetric = computed(() =>
    props.metric && props.catalog && !selectedEntry.value ? props.metric : null,
);

const metricPlaceholder = computed(() => {
    if (!props.catalog) {
        return 'Loading metrics…';
    }

    if (props.catalog.unavailable) {
        return 'Metrics unavailable';
    }

    if (props.catalog.metrics.length === 0) {
        return 'No metrics in this window';
    }

    return 'Pick a metric…';
});

const metricDisabled = computed(
    () =>
        !props.catalog ||
        (props.catalog.metrics.length === 0 && orphanMetric.value === null),
);

const metricValue = computed({
    get: () => props.metric ?? undefined,
    set: (value: string | undefined) => emit('update:metric', value || null),
});

const serviceOptions = computed(() => {
    const names = new Set(selectedEntry.value?.services ?? []);

    if (props.service) {
        names.add(props.service);
    }

    return [...names].sort((a, b) => a.localeCompare(b));
});

const attributeKeys = computed(() =>
    (props.attributes?.attributes ?? []).map((attribute) => attribute.key),
);

/** The group-by key stays offered even when the attributes have not caught up. */
const groupByOptions = computed(() => {
    const keys = new Set(attributeKeys.value);

    if (props.groupBy) {
        keys.add(props.groupBy);
    }

    return [...keys];
});

const groupByChoices = computed(() =>
    groupByOptions.value.map((key) => ({ value: key, label: key })),
);

const projectOptions = computed(() =>
    props.projects.map((option) => ({
        value: option.slug,
        label: option.name,
    })),
);

const aggOptions = METRIC_AGGREGATIONS.map((option) => ({
    value: option.value,
    label: option.label,
}));

/** A custom window is shown as such; it cannot be picked from here. */
const rangeOptions = computed(() => [
    ...RANGE_PRESETS.map((preset) => ({
        value: preset.value,
        label: preset.label,
    })),
    ...(props.range === 'custom'
        ? [{ value: 'custom', label: 'Custom range' }]
        : []),
]);

function pickRange(value: string | null) {
    if (value === null || value === 'custom' || value === props.range) {
        return;
    }

    emit('update:range', value as LogRangePreset);
}

const filterEntries = computed(() => Object.entries(props.where));

const canAddFilter = computed(
    () =>
        props.metric !== null &&
        filterEntries.value.length < MAX_METRIC_FILTERS,
);

const addingFilter = ref(false);
const draftKey = ref<string | undefined>(undefined);
const draftValue = ref('');

/** Keys already filtered on are not offered twice; one value per key. */
const draftKeyOptions = computed(() =>
    attributeKeys.value.filter((key) => !(key in props.where)),
);

const draftValueOptions = computed(
    () =>
        props.attributes?.attributes.find(
            (attribute) => attribute.key === draftKey.value,
        )?.values ?? [],
);

function openFilterForm() {
    draftKey.value = draftKeyOptions.value[0];
    draftValue.value = '';
    addingFilter.value = true;
}

function closeFilterForm() {
    addingFilter.value = false;
}

function addFilter() {
    const key = draftKey.value?.trim();
    const value = draftValue.value.trim();

    if (!key || !value || !canAddFilter.value) {
        return;
    }

    emit('update:where', { ...props.where, [key]: value });
    closeFilterForm();
}

function removeFilter(key: string) {
    const next = { ...props.where };

    delete next[key];
    emit('update:where', next);
}
</script>

<template>
    <!--
      One wrapping row: the metric and the service first, as the widest
      controls, then the filters as chips, Live and Reset last. The attribute
      filters join the row once there is a metric — its keys are what they
      offer.
    -->
    <div class="flex flex-wrap items-center gap-2" data-test="metrics-toolbar">
        <div class="min-w-64 flex-[2]">
            <Label class="sr-only" for="metrics-metric">Metric</Label>
            <Select v-model="metricValue" :disabled="metricDisabled">
                <SelectTrigger
                    id="metrics-metric"
                    class="h-9 w-full justify-start gap-1.5 rounded-lg border-border pr-2.5 pl-3 text-sm shadow-none data-[size=default]:h-9 dark:bg-transparent"
                    data-test="metrics-metric"
                >
                    <span class="text-muted-foreground">Metric</span>
                    <!--
                      The item shows its unit beside the name; the trigger
                      shows the name alone, since reka joins an item's text
                      and would print "process.memory.usageBy".
                    -->
                    <SelectValue
                        :placeholder="metricPlaceholder"
                        :class="
                            metric
                                ? 'min-w-0 flex-1 font-mono font-medium text-foreground'
                                : 'min-w-0 flex-1'
                        "
                    >
                        {{ metric ?? metricPlaceholder }}
                    </SelectValue>
                </SelectTrigger>
                <SelectContent class="max-h-96">
                    <SelectGroup v-if="orphanMetric">
                        <SelectLabel>Not reported in this window</SelectLabel>
                        <SelectItem :value="orphanMetric" class="font-mono">
                            {{ orphanMetric }}
                        </SelectItem>
                    </SelectGroup>
                    <SelectGroup
                        v-for="group in catalogGroups"
                        :key="group.type"
                    >
                        <SelectLabel>{{ group.label }}</SelectLabel>
                        <SelectItem
                            v-for="entry in group.metrics"
                            :key="entry.name"
                            :value="entry.name"
                            :data-test="`metrics-metric-option-${entry.name}`"
                        >
                            <span class="truncate font-mono">
                                {{ entry.name }}
                            </span>
                            <span
                                v-if="entry.unit"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ entry.unit }}
                            </span>
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>

        <div class="min-w-48 flex-1">
            <Label class="sr-only" for="metrics-service">
                Filter by service
            </Label>
            <!--
              A datalist, not a select: the suggestions are the services
              that reported this metric lately, and a service outside them
              is still a fair question.
            -->
            <Input
                id="metrics-service"
                v-model="serviceTerm"
                data-test="metrics-service"
                list="metrics-service-options"
                autocomplete="off"
                placeholder="Service…"
                class="h-9 rounded-lg font-mono text-sm shadow-none placeholder:font-sans dark:bg-transparent"
                @input="emitService"
            />
            <datalist
                id="metrics-service-options"
                data-test="metrics-service-options"
            >
                <option
                    v-for="name in serviceOptions"
                    :key="name"
                    :value="name"
                />
            </datalist>
        </div>

        <FilterSelect
            id="metrics-range"
            label="Window"
            data-test="metrics-range"
            :model-value="range"
            :options="rangeOptions"
            @update:model-value="pickRange"
        />

        <FilterSelect
            v-if="projects.length > 1 || project !== null"
            id="metrics-project"
            label="Project"
            all-label="All projects"
            data-test="metrics-project"
            :model-value="project"
            :options="projectOptions"
            @update:model-value="emit('update:project', $event)"
        />

        <FilterSelect
            v-if="metric !== null"
            id="metrics-group-by"
            label="Group by"
            all-label="None"
            all-short="None"
            data-test="metrics-group-by"
            :model-value="groupBy"
            :options="groupByChoices"
            @update:model-value="emit('update:groupBy', $event)"
        />

        <!--
          Aggregation combines the series of a level into one line per
          group. A rate is always summed and a distribution is read at a
          percentile, so for those the control would change nothing.
        -->
        <FilterSelect
            v-if="kind === 'value'"
            id="metrics-agg"
            label="Combine"
            data-test="metrics-agg"
            :model-value="agg"
            :options="aggOptions"
            @update:model-value="
                emit('update:agg', ($event ?? agg) as MetricAggregation)
            "
        />

        <div
            v-if="metric !== null"
            class="contents"
            data-test="metrics-filters"
        >
            <span
                v-for="[key, value] in filterEntries"
                :key="key"
                class="inline-flex h-9 max-w-full items-center gap-1.5 rounded-lg border pr-1.5 pl-3 text-sm"
                :data-test="`metrics-filter-${key}`"
            >
                <span class="flex min-w-0 gap-1 font-mono text-xs">
                    <span class="shrink-0 text-muted-foreground"
                        >{{ key }} =</span
                    >
                    <span class="truncate font-medium text-foreground">{{
                        value
                    }}</span>
                </span>
                <button
                    type="button"
                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="`Remove filter ${key} = ${value}`"
                    :data-test="`metrics-filter-remove-${key}`"
                    @click="removeFilter(key)"
                >
                    <X class="size-3.5" />
                </button>
            </span>

            <form
                v-if="addingFilter"
                class="flex flex-wrap items-center gap-2"
                data-test="metrics-filter-form"
                @submit.prevent="addFilter"
                @keydown.esc="closeFilterForm"
            >
                <Label class="sr-only" for="metrics-filter-key">
                    Attribute
                </Label>
                <Select v-model="draftKey">
                    <SelectTrigger
                        id="metrics-filter-key"
                        class="h-9 min-w-40 rounded-lg border-border font-mono text-sm shadow-none data-[size=default]:h-9 dark:bg-transparent"
                        data-test="metrics-filter-key"
                    >
                        <SelectValue
                            :placeholder="
                                attributes ? 'Attribute' : 'Loading attributes…'
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="key in draftKeyOptions"
                            :key="key"
                            :value="key"
                            class="font-mono"
                        >
                            {{ key }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <span class="font-mono text-xs text-muted-foreground">=</span>

                <Label class="sr-only" for="metrics-filter-value">Value</Label>
                <Input
                    id="metrics-filter-value"
                    v-model="draftValue"
                    data-test="metrics-filter-value"
                    list="metrics-filter-value-options"
                    autocomplete="off"
                    placeholder="Value…"
                    class="h-9 w-48 rounded-lg font-mono text-sm shadow-none dark:bg-transparent"
                />
                <datalist id="metrics-filter-value-options">
                    <option
                        v-for="value in draftValueOptions"
                        :key="value"
                        :value="value"
                    />
                </datalist>

                <Button
                    type="submit"
                    :disabled="!draftKey || draftValue.trim() === ''"
                    data-test="metrics-filter-apply"
                >
                    Add
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="closeFilterForm"
                >
                    Cancel
                </Button>
            </form>

            <Button
                v-else
                type="button"
                variant="ghost"
                class="text-muted-foreground"
                :disabled="!canAddFilter"
                :title="
                    canAddFilter
                        ? undefined
                        : `At most ${MAX_METRIC_FILTERS} filters`
                "
                data-test="metrics-filter-add"
                @click="openFilterForm"
            >
                <Plus class="size-3.5" />
                Filter
            </Button>
        </div>

        <span
            class="mx-1 hidden h-5 w-px bg-border sm:block"
            aria-hidden="true"
        />

        <MetricsLiveToggle
            :live="live ?? false"
            :available="liveAvailable"
            :refreshing="refreshing"
            @update:live="emit('update:live', $event)"
        />

        <Button
            v-if="canReset"
            type="button"
            variant="ghost"
            class="text-muted-foreground"
            data-test="metrics-reset"
            @click="emit('reset')"
        >
            <RotateCcw class="size-3.5" />
            Reset
        </Button>
    </div>
</template>
