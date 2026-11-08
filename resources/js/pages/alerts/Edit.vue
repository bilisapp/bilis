<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AlertInstanceRow from '@/components/AlertInstanceRow.vue';
import FilterSelect from '@/components/FilterSelect.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageSection from '@/components/PageSection.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import ThresholdChart from '@/components/ThresholdChart.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    AGGREGATION_LABEL,
    ALERT_SEVERITY_LABEL,
    ALERT_SOURCE_LABEL,
    CHANNEL_TYPE_LABEL,
    COMPARE_OFFSETS,
    HOLD_PRESETS,
    NO_DATA_LABEL,
    WINDOW_PRESETS,
    canCompare,
    describeQuery,
    formatCondition,
    formatSeconds,
    thresholdHint,
} from '@/lib/alerts';
import { SEVERITY_DOT_CLASS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import {
    index as alertsIndex,
    preview as alertsPreview,
    store as alertsStore,
    update as alertsUpdate,
} from '@/routes/alerts';
import { edit as teamEdit } from '@/routes/teams';
import type {
    AlertChannelRef,
    AlertCondition,
    AlertEditorOptions,
    AlertMetricCatalog,
    AlertPreviewResult,
    AlertQuery,
    AlertRuleEditing,
    AlertRuleForm,
    AlertSeverity,
    AlertSourceKey,
    SeverityLevel,
    Team,
} from '@/types';

const props = defineProps<{
    /** Null for a new rule. */
    rule: AlertRuleEditing | null;
    defaults: AlertRuleForm;
    projects: { id: number; name: string; slug: string }[];
    channels: AlertChannelRef[];
    options: AlertEditorOptions;
    /** Deferred: service names seen in logs and traces. */
    services?: string[];
    /** Deferred: metrics reported lately. */
    metricCatalog?: AlertMetricCatalog;
}>();

defineOptions({
    layout: (layoutProps: {
        currentTeam?: Team | null;
        rule?: AlertRuleEditing | null;
    }) => ({
        breadcrumbs: [
            {
                title: 'Alerts',
                href: layoutProps.currentTeam
                    ? alertsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            {
                title: layoutProps.rule ? layoutProps.rule.name : 'New rule',
                href: '#',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

/** A source's empty query, so switching source never carries the last one's fields. */
function emptyQuery(source: AlertSourceKey): AlertQuery {
    if (source === 'traces') {
        return {
            filters: { services: [], span_name: '', kind: null },
            aggregation: 'p95',
            group_by: null,
        };
    }

    if (source === 'metrics') {
        return { metric: '', filters: {}, aggregation: 'avg', group_by: null };
    }

    return {
        filters: { services: [], severities: [], search: '' },
        aggregation: 'count',
        group_by: null,
    };
}

/** The stored query with every field the form binds present. */
function withEmptyFields(
    source: AlertSourceKey,
    query: AlertQuery,
): AlertQuery {
    const empty = emptyQuery(source);

    return {
        ...empty,
        ...query,
        filters: { ...(empty.filters ?? {}), ...(query.filters ?? {}) },
        group_by: query.group_by ?? null,
        compare: query.compare ?? null,
        min_events: query.min_events ?? null,
    };
}

const initial: AlertRuleForm = props.rule
    ? {
          name: props.rule.name,
          project_ids: props.rule.project_ids,
          source: props.rule.source,
          query: withEmptyFields(props.rule.source, props.rule.query),
          condition: props.rule.condition,
          threshold: props.rule.threshold,
          window_seconds: props.rule.window_seconds,
          interval_seconds: props.rule.interval_seconds,
          for_seconds: props.rule.for_seconds,
          keep_firing_for_seconds: props.rule.keep_firing_for_seconds,
          no_data_state: props.rule.no_data_state,
          severity: props.rule.severity,
          channel_ids: props.rule.channel_ids,
      }
    : {
          ...props.defaults,
          query: withEmptyFields(props.defaults.source, props.defaults.query),
      };

const form = useForm<AlertRuleForm>(initial);

const filters = computed(
    () => (form.query.filters ?? {}) as Record<string, unknown>,
);

function setFilter(key: string, value: string | string[] | null) {
    form.query = {
        ...form.query,
        filters: { ...(form.query.filters ?? {}), [key]: value },
    };
}

function setQuery(changes: Partial<AlertQuery>) {
    form.query = { ...form.query, ...changes };

    // A log ratio, and every metric, cannot be compared with a baseline.
    if (
        form.query.compare &&
        !canCompare(form.source, form.query.aggregation)
    ) {
        form.query = { ...form.query, compare: null };
    }
}

const compareOptions = COMPARE_OFFSETS.map((option) => ({
    value: String(option.value),
    label: option.label,
}));

function setCompare(value: string | null) {
    setQuery({ compare: value ? { offset_seconds: Number(value) } : null });
}

function setMinEvents(value: string | number) {
    const number = Math.floor(Number(value));

    setQuery({
        min_events:
            String(value).trim() === '' ||
            !Number.isFinite(number) ||
            number < 1
                ? null
                : number,
    });
}

function changeSource(source: AlertSourceKey) {
    if (source === form.source) {
        return;
    }

    form.source = source;
    form.query = emptyQuery(source);
    metricWhere.value = [];

    if (
        source === 'traces' &&
        form.window_seconds > props.options.traces.maxWindowSeconds
    ) {
        form.window_seconds = props.options.traces.maxWindowSeconds;
    }
}

const sourceOptions = computed(() =>
    props.options.sources.map((source) => ({
        value: source,
        label: ALERT_SOURCE_LABEL[source],
    })),
);

const aggregationOptions = computed(() =>
    props.options[form.source].aggregations.map((aggregation) => ({
        value: aggregation,
        label: AGGREGATION_LABEL[aggregation] ?? aggregation,
    })),
);

const AGGREGATION_HELP: Record<string, string> = {
    'logs:count': 'Matching lines in the window.',
    'logs:rate': 'Matching lines per minute.',
    'logs:ratio':
        'Matching lines over every line from the same projects and services.',
    'traces:count': 'Matching spans in the window.',
    'traces:error_ratio': 'The share of matching spans with an error status.',
    'traces:p50': 'The median span duration.',
    'traces:p95': 'The 95th percentile span duration.',
    'traces:p99': 'The 99th percentile span duration.',
    'metrics:avg': 'The metric as charted, averaged over the window.',
    'metrics:max': 'The highest reading in the window.',
    'metrics:min': 'The lowest reading in the window.',
    'metrics:last': 'The newest reading in the window.',
    'metrics:p50': 'Histograms only: the median, averaged over the window.',
    'metrics:p95': 'Histograms only: the 95th percentile.',
    'metrics:p99': 'Histograms only: the 99th percentile.',
};

/** Services: the ones seen lately plus any already chosen, sorted. */
const selectedServices = computed(() =>
    Array.isArray(filters.value.services)
        ? (filters.value.services as string[])
        : [],
);

const serviceChoices = computed(() =>
    [...new Set([...(props.services ?? []), ...selectedServices.value])].sort(),
);

function toggleService(service: string) {
    const current = selectedServices.value;

    setFilter(
        'services',
        current.includes(service)
            ? current.filter((name) => name !== service)
            : [...current, service],
    );
}

const newService = ref('');

function addService() {
    const name = newService.value.trim();

    if (name !== '' && !selectedServices.value.includes(name)) {
        setFilter('services', [...selectedServices.value, name]);
    }

    newService.value = '';
}

const selectedSeverities = computed(() =>
    Array.isArray(filters.value.severities)
        ? (filters.value.severities as string[])
        : [],
);

function toggleSeverity(level: string) {
    const current = selectedSeverities.value;

    setFilter(
        'severities',
        current.includes(level)
            ? current.filter((name) => name !== level)
            : [...current, level],
    );
}

/** Metric attribute filters as editable rows, written back as an object. */
const metricWhere = ref<{ key: string; value: string }[]>(
    form.source === 'metrics'
        ? Object.entries(filters.value).map(([key, value]) => ({
              key,
              value: String(value),
          }))
        : [],
);

watch(
    metricWhere,
    (rows) => {
        if (form.source !== 'metrics') {
            return;
        }

        form.query = {
            ...form.query,
            filters: Object.fromEntries(
                rows
                    .filter((row) => row.key.trim() !== '')
                    .map((row) => [row.key.trim(), row.value]),
            ),
        };
    },
    { deep: true },
);

const metricNames = computed(
    () => props.metricCatalog?.metrics.map((metric) => metric.name) ?? [],
);

const chosenMetric = computed(() =>
    props.metricCatalog?.metrics.find(
        (metric) => metric.name === form.query.metric,
    ),
);

const CONDITION_WORDS: Record<AlertCondition, string> = {
    '>': 'above',
    '>=': 'at or above',
    '<': 'below',
    '<=': 'at or below',
};

const conditionOptions = computed(() =>
    props.options.conditions.map((condition) => ({
        value: condition,
        label: `${condition} ${CONDITION_WORDS[condition]}`,
    })),
);

/** A preset list plus whatever the rule already holds, so nothing is lost. */
function durationOptions(presets: number[], current: number, max: number) {
    return [...new Set([...presets, current])]
        .filter((seconds) => seconds <= max)
        .sort((a, b) => a - b)
        .map((seconds) => ({
            value: String(seconds),
            label: seconds === 0 ? 'Immediately' : formatSeconds(seconds),
        }));
}

const maxWindow = computed(() =>
    form.source === 'traces'
        ? props.options.traces.maxWindowSeconds
        : props.options.maxWindowSeconds,
);

const windowOptions = computed(() =>
    durationOptions(WINDOW_PRESETS, form.window_seconds, maxWindow.value),
);

const forOptions = computed(() =>
    durationOptions(
        HOLD_PRESETS,
        form.for_seconds,
        props.options.maxHoldSeconds,
    ),
);

const keepOptions = computed(() =>
    durationOptions(
        HOLD_PRESETS,
        form.keep_firing_for_seconds,
        props.options.maxHoldSeconds,
    ),
);

const intervalOptions = computed(() =>
    props.options.intervals.map((seconds) => ({
        value: String(seconds),
        label: `Every ${formatSeconds(seconds)}`,
    })),
);

const noDataOptions = computed(() =>
    props.options.noDataStates.map((state) => ({
        value: state,
        label: NO_DATA_LABEL[state],
    })),
);

const severityOptions = computed(() =>
    props.options.severities.map((severity) => ({
        value: severity,
        label: ALERT_SEVERITY_LABEL[severity],
    })),
);

const groupByOptions = computed(() =>
    form.source === 'metrics'
        ? []
        : props.options[form.source].groupBy.map((key) => ({
              value: key,
              label: key === 'service.name' ? 'Service' : key,
          })),
);

const kindOptions = computed(() =>
    props.options.traces.kinds.map((kind) => ({ value: kind, label: kind })),
);

const thresholdNumber = computed<number | null>(() => {
    const value = Number(form.threshold);

    return String(form.threshold).trim() === '' || !Number.isFinite(value)
        ? null
        : value;
});

/** The rule in one line, as the list will print it. */
const sentence = computed(() =>
    thresholdNumber.value === null
        ? describeQuery(form.source, form.query)
        : `${formatCondition({
              source: form.source,
              query: form.query,
              condition: form.condition,
              threshold: thresholdNumber.value,
              forSeconds: form.for_seconds,
          })} · ${describeQuery(form.source, form.query)} · ${formatSeconds(form.window_seconds)} window`,
);

/** Null reads every project of the team, including ones created later. */
const allProjects = computed(() => form.project_ids === null);

function toggleAllProjects() {
    form.project_ids = allProjects.value
        ? props.projects.map((project) => project.id)
        : null;
}

function toggleProject(id: number) {
    const current = form.project_ids ?? [];

    form.project_ids = current.includes(id)
        ? current.filter((projectId) => projectId !== id)
        : [...current, id];
}

function toggleChannel(id: number) {
    form.channel_ids = form.channel_ids.includes(id)
        ? form.channel_ids.filter((channelId) => channelId !== id)
        : [...form.channel_ids, id];
}

/** Errors for `query.*` arrive keyed by path; the first one under a prefix. */
const queryError = (prefix: string): string | undefined =>
    Object.entries(form.errors as Record<string, string | undefined>).find(
        ([field]) => field === prefix || field.startsWith(`${prefix}.`),
    )?.[1];

function submit() {
    if (props.rule) {
        form.put(alertsUpdate([teamSlug.value, props.rule.id]).url, {
            preserveScroll: true,
        });
    } else {
        form.post(alertsStore(teamSlug.value).url, { preserveScroll: true });
    }
}

/*
 * The preview: the draft's definition, posted when it settles. A newer
 * request cancels the one in flight, and a response for an older draft is
 * dropped, so the chart always describes what the form says now.
 */
const preview = ref<AlertPreviewResult | null>(null);
const previewProblem = ref<string | null>(null);
const previewHttp = useHttp<Record<string, never>, AlertPreviewResult>();
let previewTimer: ReturnType<typeof setTimeout> | null = null;
let previewSequence = 0;

const definition = computed(() => ({
    project_ids: form.project_ids,
    source: form.source,
    query: form.query,
    condition: form.condition,
    threshold: form.threshold,
    window_seconds: form.window_seconds,
    interval_seconds: form.interval_seconds,
    for_seconds: form.for_seconds,
    keep_firing_for_seconds: form.keep_firing_for_seconds,
    no_data_state: form.no_data_state,
    severity: form.severity,
}));

/** The fields the chart depends on; the threshold only moves the line. */
const previewKey = computed(() =>
    JSON.stringify({
        project_ids: form.project_ids,
        source: form.source,
        query: form.query,
        window_seconds: form.window_seconds,
    }),
);

async function loadPreview() {
    const sequence = ++previewSequence;

    if (form.project_ids !== null && form.project_ids.length === 0) {
        previewProblem.value = 'Pick a project to see a preview.';

        return;
    }

    if (form.source === 'metrics' && !form.query.metric) {
        previewProblem.value = 'Pick a metric to see a preview.';

        return;
    }

    previewHttp.cancel();
    previewHttp.transform(() => ({
        ...definition.value,
        threshold: thresholdNumber.value ?? 0,
    }));

    try {
        const result = await previewHttp.post(
            alertsPreview(teamSlug.value).url,
        );

        if (sequence === previewSequence) {
            preview.value = result;
            previewProblem.value = null;
        }
    } catch {
        if (sequence !== previewSequence) {
            return;
        }

        const errors = Object.values(
            previewHttp.errors as Record<string, string | undefined>,
        ).filter(Boolean);

        previewProblem.value = errors.length
            ? `Fix this to see a preview: ${errors[0]}`
            : 'The preview could not be loaded. It will try again when the rule changes.';
    }
}

function schedulePreview() {
    if (previewTimer) {
        clearTimeout(previewTimer);
    }

    previewTimer = setTimeout(() => void loadPreview(), 600);
}

watch(previewKey, schedulePreview);

onMounted(() => void loadPreview());

onBeforeUnmount(() => {
    if (previewTimer) {
        clearTimeout(previewTimer);
    }

    previewHttp.cancel();
});

const channelsHref = computed(
    () => `${teamEdit(teamSlug.value).url}#notification-channels`,
);

const CHIP =
    'inline-flex h-7 items-center rounded-full px-3 text-xs font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
const CHIP_ON = 'bg-secondary text-foreground ring-1 ring-border';
const CHIP_OFF =
    'text-muted-foreground ring-1 ring-border/70 hover:text-foreground';
</script>

<template>
    <Head :title="rule ? `Edit ${rule.name}` : 'New alert rule'" />

    <form
        class="flex flex-1 flex-col pb-16"
        data-test="alert-rule-form"
        @submit.prevent="submit"
    >
        <PageHeader :title="rule ? rule.name : 'New alert rule'">
            <p class="font-mono text-xs" data-test="alert-rule-sentence">
                {{ sentence }}
            </p>

            <template #actions>
                <Button variant="ghost" as-child>
                    <Link :href="alertsIndex(teamSlug).url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="alert-rule-save"
                >
                    {{ rule ? 'Save rule' : 'Create rule' }}
                </Button>
            </template>
        </PageHeader>

        <!--
          A rule Bilis set up: why it exists, and what saving costs. Once
          edited, provisioning leaves it alone (specs/alerting-defaults.md §4).
        -->
        <div
            v-if="rule && rule.template"
            class="mx-4 mb-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm md:mx-10"
            role="note"
            data-test="alert-rule-template-notice"
        >
            <p>
                <span class="font-medium text-foreground">{{
                    rule.customized
                        ? 'Bilis set this up, and it has been edited since — Bilis no longer updates it.'
                        : 'Bilis set this up. Saving changes stops Bilis’ updates to it.'
                }}</span>
            </p>
            <p
                v-if="rule.why"
                class="mt-1 text-muted-foreground"
                data-test="alert-rule-template-why"
            >
                {{ rule.why }}
            </p>
        </div>

        <!-- An existing rule: what it is saying right now. -->
        <div
            v-if="rule && rule.health === 'err' && rule.lastError"
            class="mx-4 mb-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm md:mx-10"
            role="status"
            data-test="alert-rule-last-error"
        >
            <span class="font-medium text-foreground"
                >The last evaluation failed.</span
            >
            <span class="text-muted-foreground"> {{ rule.lastError }}</span>
        </div>

        <PageSection
            v-if="rule && rule.instances.length"
            title="Active now"
            flush
            class="pt-2"
        >
            <div class="mt-3 border-t">
                <AlertInstanceRow
                    v-for="instance in rule.instances"
                    :key="instance.id"
                    :instance="instance"
                    :team-slug="teamSlug"
                />
            </div>
        </PageSection>

        <PageSection
            title="What to watch"
            :class="rule && rule.instances.length ? undefined : 'pt-2'"
        >
            <div class="grid max-w-3xl gap-6">
                <div class="grid gap-2">
                    <Label for="alert-name">Name</Label>
                    <Input
                        id="alert-name"
                        v-model="form.name"
                        autocomplete="off"
                        placeholder="Checkout error spike"
                        data-test="alert-rule-name"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label>Projects</Label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="allProjects"
                            data-test="alert-rule-all-projects"
                            @update:model-value="toggleAllProjects"
                        />
                        All projects (including future ones)
                    </label>
                    <div
                        v-if="!allProjects"
                        class="flex flex-wrap gap-x-5 gap-y-2"
                    >
                        <label
                            v-for="project in projects"
                            :key="project.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    (form.project_ids ?? []).includes(
                                        project.id,
                                    )
                                "
                                :data-test="`alert-rule-project-${project.slug}`"
                                @update:model-value="toggleProject(project.id)"
                            />
                            {{ project.name }}
                        </label>
                    </div>
                    <InputError
                        :message="
                            form.errors.project_ids ?? queryError('project_ids')
                        "
                    />
                </div>

                <div class="grid gap-2">
                    <Label>Source</Label>
                    <SegmentedControl
                        :model-value="form.source"
                        :options="sourceOptions"
                        aria-label="Source"
                        data-test-prefix="alert-rule-source"
                        @update:model-value="
                            changeSource($event as AlertSourceKey)
                        "
                    />
                    <InputError :message="form.errors.source" />
                </div>

                <!-- Logs and traces: which services. -->
                <div v-if="form.source !== 'metrics'" class="grid gap-2">
                    <Label for="alert-service-add">Services</Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="service in serviceChoices"
                            :key="service"
                            type="button"
                            :aria-pressed="selectedServices.includes(service)"
                            :class="
                                cn(
                                    CHIP,
                                    'font-mono',
                                    selectedServices.includes(service)
                                        ? CHIP_ON
                                        : CHIP_OFF,
                                )
                            "
                            :data-test="`alert-rule-service-${service}`"
                            @click="toggleService(service)"
                        >
                            {{ service }}
                        </button>
                        <span
                            v-if="services === undefined"
                            class="text-xs text-muted-foreground"
                            >Loading services…</span
                        >
                        <Input
                            id="alert-service-add"
                            v-model="newService"
                            class="h-7 w-44 font-mono text-xs"
                            placeholder="Add a service"
                            @keydown.enter.prevent="addService"
                            @blur="addService"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        None picked means every service.
                    </p>
                    <InputError
                        :message="queryError('query.filters.services')"
                    />
                </div>

                <template v-if="form.source === 'logs'">
                    <div class="grid gap-2">
                        <Label>Severities</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="level in options.logs.severities"
                                :key="level"
                                type="button"
                                :aria-pressed="
                                    selectedSeverities.includes(level)
                                "
                                :class="
                                    cn(
                                        CHIP,
                                        'gap-1.5 capitalize',
                                        selectedSeverities.includes(level)
                                            ? CHIP_ON
                                            : CHIP_OFF,
                                    )
                                "
                                :data-test="`alert-rule-severity-${level}`"
                                @click="toggleSeverity(level)"
                            >
                                <span
                                    :class="
                                        cn(
                                            'size-2 rounded-[2px]',
                                            SEVERITY_DOT_CLASS[
                                                level as SeverityLevel
                                            ],
                                        )
                                    "
                                    aria-hidden="true"
                                />
                                {{ level }}
                            </button>
                        </div>
                        <InputError
                            :message="queryError('query.filters.severities')"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="alert-search">Message contains</Label>
                        <Input
                            id="alert-search"
                            :model-value="String(filters.search ?? '')"
                            class="font-mono"
                            placeholder="timeout"
                            data-test="alert-rule-search"
                            @update:model-value="
                                setFilter('search', String($event))
                            "
                        />
                        <InputError
                            :message="queryError('query.filters.search')"
                        />
                    </div>
                </template>

                <template v-if="form.source === 'traces'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="alert-span-name">Span name</Label>
                            <Input
                                id="alert-span-name"
                                :model-value="String(filters.span_name ?? '')"
                                class="font-mono"
                                placeholder="POST /checkout"
                                data-test="alert-rule-span-name"
                                @update:model-value="
                                    setFilter('span_name', String($event))
                                "
                            />
                            <InputError
                                :message="queryError('query.filters.span_name')"
                            />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label>Span kind</Label>
                            <FilterSelect
                                label="Kind"
                                :model-value="
                                    (filters.kind as string | null) ?? null
                                "
                                :options="kindOptions"
                                all-label="Any kind"
                                all-short="Any"
                                data-test="alert-rule-kind"
                                @update:model-value="setFilter('kind', $event)"
                            />
                            <InputError
                                :message="queryError('query.filters.kind')"
                            />
                        </div>
                    </div>
                </template>

                <template v-if="form.source === 'metrics'">
                    <div class="grid gap-2">
                        <Label for="alert-metric">Metric</Label>
                        <Input
                            id="alert-metric"
                            :model-value="form.query.metric ?? ''"
                            list="alert-metric-names"
                            class="font-mono"
                            placeholder="system.cpu.utilization"
                            autocomplete="off"
                            data-test="alert-rule-metric"
                            @update:model-value="
                                setQuery({ metric: String($event) })
                            "
                        />
                        <datalist id="alert-metric-names">
                            <option
                                v-for="name in metricNames"
                                :key="name"
                                :value="name"
                            />
                        </datalist>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="chosenMetric">
                                A {{ chosenMetric.type
                                }}<template v-if="chosenMetric.unit">
                                    in
                                    <span class="font-mono">{{
                                        chosenMetric.unit
                                    }}</span></template
                                >.
                            </template>
                            <template v-else-if="metricCatalog === undefined"
                                >Loading the metrics your projects
                                report…</template
                            >
                            <template v-else-if="metricCatalog.unavailable"
                                >The metric list is unavailable right now; type
                                the name.</template
                            >
                            <template v-else
                                >{{ metricNames.length }} metrics reported in
                                the last day.</template
                            >
                        </p>
                        <InputError :message="queryError('query.metric')" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Where</Label>
                        <div
                            v-for="(row, index) in metricWhere"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="row.key"
                                class="font-mono"
                                placeholder="host.name"
                                :aria-label="`Attribute ${index + 1}`"
                            />
                            <span class="text-muted-foreground">=</span>
                            <Input
                                v-model="row.value"
                                class="font-mono"
                                placeholder="web-1"
                                :aria-label="`Value ${index + 1}`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                :aria-label="`Remove attribute ${index + 1}`"
                                @click="metricWhere.splice(index, 1)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <Button
                            v-if="
                                metricWhere.length < options.metrics.maxFilters
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-fit"
                            data-test="alert-rule-where-add"
                            @click="metricWhere.push({ key: '', value: '' })"
                        >
                            <Plus /> Add attribute filter
                        </Button>
                        <InputError :message="queryError('query.filters')" />
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label>Aggregation</Label>
                    <SegmentedControl
                        :model-value="form.query.aggregation"
                        :options="aggregationOptions"
                        aria-label="Aggregation"
                        data-test-prefix="alert-rule-aggregation"
                        @update:model-value="setQuery({ aggregation: $event })"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            AGGREGATION_HELP[
                                `${form.source}:${form.query.aggregation}`
                            ]
                        }}
                    </p>
                    <InputError :message="queryError('query.aggregation')" />
                </div>

                <div class="grid gap-2">
                    <Label
                        :for="
                            form.source === 'metrics'
                                ? 'alert-group-by'
                                : undefined
                        "
                        >Group by</Label
                    >
                    <FilterSelect
                        v-if="form.source !== 'metrics'"
                        label="Group by"
                        :model-value="form.query.group_by ?? null"
                        :options="groupByOptions"
                        all-label="Don't group — one series"
                        all-short="None"
                        data-test="alert-rule-group-by"
                        class="w-fit"
                        @update:model-value="setQuery({ group_by: $event })"
                    />
                    <Input
                        v-else
                        id="alert-group-by"
                        :model-value="form.query.group_by ?? ''"
                        class="max-w-xs font-mono"
                        placeholder="host.name"
                        data-test="alert-rule-group-by"
                        @update:model-value="
                            setQuery({ group_by: String($event) || null })
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Grouped, each value is its own alert that fires and
                        resolves on its own.
                    </p>
                    <InputError :message="queryError('query.group_by')" />
                </div>
            </div>
        </PageSection>

        <PageSection title="When to fire">
            <div class="grid max-w-3xl gap-6">
                <div class="flex flex-wrap items-start gap-3">
                    <FilterSelect
                        label="Value"
                        :model-value="form.condition"
                        :options="conditionOptions"
                        data-test="alert-rule-condition"
                        @update:model-value="
                            form.condition = ($event ?? '>') as AlertCondition
                        "
                    />
                    <div class="grid gap-1">
                        <Input
                            id="alert-threshold"
                            v-model="form.threshold"
                            type="number"
                            step="any"
                            class="w-36 font-mono"
                            aria-label="Threshold"
                            data-test="alert-rule-threshold"
                        />
                    </div>
                    <FilterSelect
                        label="Over"
                        :model-value="String(form.window_seconds)"
                        :options="windowOptions"
                        data-test="alert-rule-window"
                        @update:model-value="
                            form.window_seconds = Number($event)
                        "
                    />
                </div>
                <p class="-mt-4 text-xs text-muted-foreground">
                    {{
                        thresholdHint(
                            form.source,
                            form.query.aggregation,
                            form.query.compare,
                        )
                    }}
                    <template v-if="form.source === 'traces'">
                        A trace rule reads at most a one-hour window.</template
                    >
                </p>
                <InputError
                    :message="
                        form.errors.condition ??
                        form.errors.threshold ??
                        form.errors.window_seconds
                    "
                />

                <div
                    v-if="form.source !== 'metrics'"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid content-start gap-2">
                        <Label>Compare with</Label>
                        <FilterSelect
                            v-if="
                                canCompare(form.source, form.query.aggregation)
                            "
                            label="Compare"
                            :model-value="
                                form.query.compare
                                    ? String(form.query.compare.offset_seconds)
                                    : null
                            "
                            :options="compareOptions"
                            all-label="Nothing — the value itself"
                            all-short="Off"
                            data-test="alert-rule-compare"
                            @update:model-value="setCompare"
                        />
                        <p class="text-xs text-muted-foreground">
                            <template
                                v-if="
                                    canCompare(
                                        form.source,
                                        form.query.aggregation,
                                    )
                                "
                                >The threshold becomes a multiple of the same
                                window back then — 3 is 3× normal.</template
                            >
                            <template v-else
                                >A ratio cannot be compared; use count or
                                rate.</template
                            >
                        </p>
                        <InputError :message="queryError('query.compare')" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="alert-min-events">Minimum events</Label>
                        <Input
                            id="alert-min-events"
                            :model-value="form.query.min_events ?? ''"
                            type="number"
                            min="1"
                            step="1"
                            class="w-36 font-mono"
                            placeholder="Any"
                            data-test="alert-rule-min-events"
                            @update:model-value="setMinEvents"
                        />
                        <p class="text-xs text-muted-foreground">
                            A series with fewer
                            {{ form.source === 'logs' ? 'lines' : 'spans' }}
                            in the window counts as no data.
                        </p>
                        <InputError :message="queryError('query.min_events')" />
                    </div>
                </div>

                <ThresholdChart
                    :result="preview"
                    :source="form.source"
                    :aggregation="form.query.aggregation"
                    :compare="form.query.compare"
                    :condition="form.condition"
                    :threshold="thresholdNumber"
                    :loading="previewHttp.processing"
                />
                <p
                    v-if="previewProblem"
                    class="-mt-3 text-xs text-muted-foreground"
                    data-test="alert-rule-preview-problem"
                >
                    {{ previewProblem }}
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label>Pending for</Label>
                        <FilterSelect
                            label="For"
                            :model-value="String(form.for_seconds)"
                            :options="forOptions"
                            data-test="alert-rule-for"
                            @update:model-value="
                                form.for_seconds = Number($event)
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            How long the condition must hold before it fires.
                        </p>
                        <InputError :message="form.errors.for_seconds" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label>Keep firing for</Label>
                        <FilterSelect
                            label="Keep"
                            :model-value="String(form.keep_firing_for_seconds)"
                            :options="keepOptions"
                            data-test="alert-rule-keep-firing"
                            @update:model-value="
                                form.keep_firing_for_seconds = Number($event)
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            How long it must be fine again before it resolves.
                        </p>
                        <InputError
                            :message="form.errors.keep_firing_for_seconds"
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label>Evaluate</Label>
                        <FilterSelect
                            label="Interval"
                            :model-value="String(form.interval_seconds)"
                            :options="intervalOptions"
                            data-test="alert-rule-interval"
                            @update:model-value="
                                form.interval_seconds = Number($event)
                            "
                        />
                        <InputError :message="form.errors.interval_seconds" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label>When there is no data</Label>
                        <FilterSelect
                            label="No data"
                            :model-value="form.no_data_state"
                            :options="noDataOptions"
                            data-test="alert-rule-no-data"
                            @update:model-value="
                                form.no_data_state = ($event ??
                                    'inactive') as typeof form.no_data_state
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            “Fire” turns a series that goes silent into an
                            alert.
                        </p>
                        <InputError :message="form.errors.no_data_state" />
                    </div>
                </div>
            </div>
        </PageSection>

        <PageSection title="Who to tell">
            <div class="grid max-w-3xl gap-6">
                <div class="grid gap-2">
                    <Label>Severity</Label>
                    <SegmentedControl
                        :model-value="form.severity"
                        :options="severityOptions"
                        aria-label="Severity"
                        data-test-prefix="alert-rule-severity-level"
                        @update:model-value="
                            form.severity = $event as AlertSeverity
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Carried into the notification; it changes nothing about
                        when the rule fires.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label>Channels</Label>
                    <template v-if="channels.length">
                        <label
                            v-for="channel in channels"
                            :key="channel.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    form.channel_ids.includes(channel.id)
                                "
                                :data-test="`alert-rule-channel-${channel.id}`"
                                @update:model-value="toggleChannel(channel.id)"
                            />
                            {{ channel.name }}
                            <span class="text-xs text-muted-foreground">
                                {{ CHANNEL_TYPE_LABEL[channel.type]
                                }}{{ channel.enabled ? '' : ' · off' }}
                            </span>
                        </label>
                    </template>
                    <p
                        v-else
                        class="max-w-prose text-sm text-muted-foreground"
                        data-test="alert-rule-no-channels"
                    >
                        This team has no notification channels yet, so the rule
                        will record its history and send nothing.
                        <Link
                            :href="channelsHref"
                            class="underline underline-offset-4 hover:text-foreground"
                            >Add one in team settings</Link
                        >.
                    </p>
                    <InputError
                        :message="
                            form.errors.channel_ids ?? queryError('channel_ids')
                        "
                    />
                </div>
            </div>
        </PageSection>
    </form>
</template>
