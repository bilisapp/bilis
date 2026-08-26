<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogsHistogram from '@/components/LogsHistogram.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { presetForRange, RANGE_PRESETS, SEVERITY_DOT_CLASS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import { index as logsIndex, tail as logsTail } from '@/routes/logs';
import type {
    LogEntry,
    LogFilters,
    LogHistogram,
    LogProject,
    LogRangePreset,
    LogResult,
    SeverityLevel,
    Team,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: LogFilters;
    severityLevels: SeverityLevel[];
    logs?: LogResult;
    histogram?: LogHistogram;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Logs',
                href: layoutProps.currentTeam
                    ? logsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const project = ref<string | null>(props.filters.project);
const service = ref<string | null>(props.filters.service);
const severity = ref<SeverityLevel[]>(props.filters.severity);
const search = ref<string | null>(props.filters.search);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const range = ref<LogRangePreset>(
    presetForRange(props.filters.from, props.filters.to),
);

watch(
    () => props.filters,
    (filters) => {
        project.value = filters.project;
        service.value = filters.service;
        severity.value = filters.severity;
        search.value = filters.search;
        from.value = filters.from;
        to.value = filters.to;
        range.value = presetForRange(filters.from, filters.to);
        tailRows.value = [];
    },
);

/**
 * The window that should be sent to the server for the active preset.
 */
const resolvedWindow = (): { from: string; to: string } => {
    const preset = RANGE_PRESETS.find((item) => item.value === range.value);

    if (!preset) {
        return { from: from.value, to: to.value };
    }

    const now = new Date();

    return {
        from: new Date(now.getTime() - preset.minutes * 60_000).toISOString(),
        to: now.toISOString(),
    };
};

const filterQuery = (
    window: { from: string; to: string },
    cursor: string | null = null,
): Record<string, string | string[]> => {
    const query: Record<string, string | string[]> = {
        from: window.from,
        to: window.to,
    };

    if (project.value) {
        query.project = project.value;
    }

    if (service.value) {
        query.service = service.value;
    }

    if (severity.value.length > 0) {
        query.severity = severity.value;
    }

    if (search.value) {
        query.search = search.value;
    }

    if (cursor) {
        query.cursor = cursor;
    }

    return query;
};

const applyFilters = (cursor: string | null = null) => {
    const window = resolvedWindow();

    router.get(logsIndex(teamSlug.value).url, filterQuery(window, cursor), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const onRange = (value: LogRangePreset) => {
    range.value = value;

    if (value !== 'custom') {
        applyFilters();
    }
};

const onWindow = (window: { from: string; to: string }) => {
    from.value = window.from;
    to.value = window.to;
    applyFilters();
};

const onProject = (value: string | null) => {
    project.value = value;
    applyFilters();
};

const onService = (value: string | null) => {
    service.value = value;
    applyFilters();
};

const onSeverity = (value: SeverityLevel[]) => {
    severity.value = value;
    applyFilters();
};

const onSearch = (value: string | null) => {
    search.value = value;
    applyFilters();
};

const expandedKey = ref<string | null>(null);
const liveTail = ref(false);
const tailRows = ref<LogEntry[]>([]);
const scrolledDown = ref(false);

const entryKey = (entry: LogEntry, index: number) =>
    `${entry.timestamp}|${entry.spanId}|${index}`;

/**
 * Rows that arrived in the most recent tail poll, so they can announce
 * themselves once and then settle into the stream.
 */
const freshKeys = ref<Set<string>>(new Set());

let freshTimer: ReturnType<typeof setTimeout> | null = null;

const freshId = (entry: LogEntry) => `${entry.timestamp}|${entry.spanId}`;

const markFresh = (entries: LogEntry[]) => {
    freshKeys.value = new Set(entries.map(freshId));

    if (freshTimer !== null) {
        clearTimeout(freshTimer);
    }

    freshTimer = setTimeout(() => {
        freshKeys.value = new Set();
        freshTimer = null;
    }, 1_500);
};

const rows = computed<LogEntry[]>(() => [
    ...tailRows.value,
    ...(props.logs?.rows ?? []),
]);

const unavailable = computed(() => props.logs?.unavailable === true);

const tailPaused = computed(
    () => expandedKey.value !== null || scrolledDown.value,
);

const tailRequest = useHttp<{ after: string }, LogResult>({ after: '' });

const newestTimestamp = () => rows.value[0]?.timestamp ?? props.filters.to;

const fetchTail = async () => {
    if (!liveTail.value || tailPaused.value || tailRequest.processing) {
        return;
    }

    tailRequest.after = newestTimestamp();

    try {
        const result = await tailRequest.get(
            logsTail(teamSlug.value, {
                query: filterQuery({ from: from.value, to: to.value }),
            }).url,
        );

        if (result?.rows?.length) {
            tailRows.value = [...result.rows, ...tailRows.value].slice(0, 500);
            markFresh(result.rows);
        }
    } catch {
        liveTail.value = false;
    }
};

let tailTimer: ReturnType<typeof setInterval> | null = null;

const stopTail = () => {
    if (tailTimer !== null) {
        clearInterval(tailTimer);
        tailTimer = null;
    }

    if (freshTimer !== null) {
        clearTimeout(freshTimer);
        freshTimer = null;
    }
};

watch(liveTail, (enabled) => {
    stopTail();

    if (enabled) {
        void fetchTail();
        tailTimer = setInterval(() => void fetchTail(), 2000);
    }
});

watch(expandedKey, (value) => {
    if (value !== null) {
        return;
    }

    void fetchTail();
});

onBeforeUnmount(stopTail);

const onScroll = (event: Event) => {
    scrolledDown.value = (event.target as HTMLElement).scrollTop > 32;
};

const toggleExpanded = (key: string) => {
    expandedKey.value = expandedKey.value === key ? null : key;
};

/**
 * Clicking a histogram bar narrows the viewer to that bucket.
 */
const onZoom = (window: { from: string; to: string }) => {
    range.value = 'custom';
    onWindow(window);
};

/**
 * The recovery offered by the empty state: jump to the widest preset.
 */
const onWiden = () => onRange('7d');

const activeFilterCount = computed(
    () =>
        (project.value ? 1 : 0) +
        (service.value ? 1 : 0) +
        (search.value ? 1 : 0) +
        (severity.value.length > 0 ? 1 : 0),
);
</script>

<template>
    <Head title="Logs" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <LogsToolbar
            :projects="projects"
            :project="project"
            :service="service"
            :severity="severity"
            :search="search"
            :range="range"
            :from="from"
            :to="to"
            :live-tail="liveTail"
            :tailing="tailRequest.processing"
            @update:project="onProject"
            @update:service="onService"
            @update:severity="onSeverity"
            @update:search="onSearch"
            @update:range="onRange"
            @update:window="onWindow"
            @update:live-tail="liveTail = $event"
        />

        <AlertError
            v-if="unavailable"
            title="Log storage is busy"
            :errors="[
                'ClickHouse could not answer this query right now. Narrow the time range or try again in a moment.',
            ]"
        />

        <LogsHistogram
            :histogram="histogram"
            :severity="severity"
            @zoom="onZoom"
        />

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border bg-card"
            data-test="logs-list"
        >
            <!--
              The stream's own header: what you are looking at, and whether it
              is still moving. It lives outside the scroll area so the state
              stays readable however far down the reader has gone.
            -->
            <header
                class="flex items-center gap-2 border-b px-3 py-1.5 text-xs"
            >
                <p v-if="logs" class="text-muted-foreground">
                    <span class="font-medium text-foreground tabular-nums">
                        {{ rows.length.toLocaleString() }}
                    </span>
                    {{ rows.length === 1 ? 'line' : 'lines' }} loaded
                    <template v-if="activeFilterCount > 0">
                        · {{ activeFilterCount }}
                        {{ activeFilterCount === 1 ? 'filter' : 'filters' }}
                        active
                    </template>
                </p>
                <p v-else class="text-muted-foreground">Loading lines…</p>

                <p
                    v-if="liveTail && tailPaused"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-severity-warn/40 bg-severity-warn/10 px-2 py-0.5 font-medium text-severity-warn"
                    data-test="logs-tail-paused"
                >
                    <span class="size-1.5 rounded-full bg-current" />
                    Tail paused while you read
                </p>
                <p
                    v-else-if="liveTail"
                    class="ml-auto inline-flex items-center gap-1.5 font-medium text-severity-info"
                    data-test="logs-tail-live"
                >
                    <span class="relative flex size-1.5">
                        <span
                            class="absolute inline-flex size-1.5 animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden"
                        />
                        <span
                            class="relative inline-flex size-1.5 rounded-full bg-current"
                        />
                    </span>
                    Tailing
                </p>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto" @scroll="onScroll">
                <!--
                  The skeleton mirrors the real row: timestamp, severity,
                  service, body. A row-shaped wait reads as "logs are coming"
                  rather than "a box is loading".
                -->
                <div v-if="!logs" data-test="logs-skeleton">
                    <div
                        v-for="index in 14"
                        :key="index"
                        class="flex items-center gap-3 border-b border-b-sidebar-border/70 px-3 py-1.5"
                    >
                        <Skeleton class="h-3 w-3.5 shrink-0 rounded-sm" />
                        <Skeleton class="h-3 w-40 shrink-0" />
                        <Skeleton class="h-3 w-14 shrink-0" />
                        <Skeleton class="h-3 w-28 shrink-0" />
                        <Skeleton
                            class="h-3 flex-1"
                            :style="{
                                maxWidth: `${40 + ((index * 37) % 55)}%`,
                            }"
                        />
                    </div>
                </div>

                <div
                    v-else-if="rows.length === 0 && unavailable"
                    class="flex h-full flex-col items-center justify-center gap-2 px-6 py-16 text-center"
                    data-test="logs-unreachable"
                >
                    <p class="text-sm font-semibold">
                        No lines to show while storage is busy
                    </p>
                    <p
                        class="max-w-sm text-sm text-balance text-muted-foreground"
                    >
                        This is not an empty window — the query never ran. The
                        lines are still in ClickHouse.
                    </p>
                </div>

                <div
                    v-else-if="rows.length === 0"
                    class="flex h-full flex-col items-center justify-center gap-4 px-6 py-16 text-center"
                    data-test="logs-empty"
                >
                    <!--
                      The severity ramp, drawn flat: the shape the stream would
                      have if it had anything to show.
                    -->
                    <div class="flex w-40 items-end gap-1.5" aria-hidden="true">
                        <span
                            v-for="level in severityLevels"
                            :key="level"
                            :class="
                                cn(
                                    'h-0.5 flex-1 rounded-full opacity-40',
                                    SEVERITY_DOT_CLASS[level],
                                )
                            "
                        />
                    </div>

                    <div class="space-y-1">
                        <p class="text-sm font-semibold">
                            Nothing landed in this window
                        </p>
                        <p
                            class="max-w-sm text-sm text-balance text-muted-foreground"
                        >
                            <template v-if="activeFilterCount > 0">
                                {{ activeFilterCount }}
                                {{
                                    activeFilterCount === 1
                                        ? 'filter is'
                                        : 'filters are'
                                }}
                                active. Widen the window or clear a filter to
                                see more.
                            </template>
                            <template v-else>
                                Point an OTLP exporter at this project's ingest
                                endpoint and its lines will show up here.
                            </template>
                        </p>
                    </div>

                    <Button
                        v-if="range !== '7d'"
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="logs-widen"
                        @click="onWiden"
                    >
                        Search the last 7 days
                    </Button>
                </div>

                <template v-else>
                    <LogEntryRow
                        v-for="(entry, index) in rows"
                        :key="entryKey(entry, index)"
                        :entry="entry"
                        :expanded="expandedKey === entryKey(entry, index)"
                        :fresh="freshKeys.has(freshId(entry))"
                        @toggle="toggleExpanded(entryKey(entry, index))"
                    />

                    <div v-if="logs.nextCursor" class="p-3 text-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="logs-load-older"
                            @click="applyFilters(logs.nextCursor)"
                        >
                            Load older logs
                        </Button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
