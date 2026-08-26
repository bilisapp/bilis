<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { presetForRange, RANGE_PRESETS } from '@/lib/logs';
import { index as logsIndex, tail as logsTail } from '@/routes/logs';
import type {
    LogEntry,
    LogFilters,
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
</script>

<template>
    <Head title="Logs" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <Heading
            variant="small"
            title="Logs"
            description="Search and tail the logs your projects have sent."
        />

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

        <p
            v-if="liveTail && tailPaused"
            class="text-xs text-muted-foreground"
            data-test="logs-tail-paused"
        >
            Live tail paused while you are reading. Scroll back to the top and
            collapse the expanded row to resume.
        </p>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-y-auto rounded-xl border"
            data-test="logs-list"
            @scroll="onScroll"
        >
            <div v-if="!logs" class="space-y-2 p-3" data-test="logs-skeleton">
                <Skeleton
                    v-for="index in 12"
                    :key="index"
                    class="h-5 w-full animate-pulse"
                />
            </div>

            <div
                v-else-if="rows.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-1 p-10 text-center"
                data-test="logs-empty"
            >
                <p class="text-sm font-medium">No logs in this window</p>
                <p class="text-sm text-muted-foreground">
                    Widen the time range, clear a filter, or send your first log
                    to a project.
                </p>
            </div>

            <template v-else>
                <LogEntryRow
                    v-for="(entry, index) in rows"
                    :key="entryKey(entry, index)"
                    :entry="entry"
                    :expanded="expandedKey === entryKey(entry, index)"
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
</template>
