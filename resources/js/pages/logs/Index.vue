<script setup lang="ts">
import { Head, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import GetStartedPanel from '@/components/GetStartedPanel.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogsHistogram from '@/components/LogsHistogram.vue';
import LogsShortcutsDialog from '@/components/LogsShortcutsDialog.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useLogKeyboard } from '@/composables/useLogKeyboard';
import { useTailStatus } from '@/composables/useTailStatus';
import { useTailTabBadge } from '@/composables/useTailTabBadge';
import {
    DEFAULT_RANGE_PRESET,
    presetForRange,
    RANGE_PRESETS,
    SEVERITY_DOT_CLASS,
} from '@/lib/logs';
import { cn } from '@/lib/utils';
import {
    index as logsIndex,
    older as logsOlder,
    tail as logsTail,
} from '@/routes/logs';
import type {
    LogEntry,
    LogFilters,
    LogHistogram,
    LogOnboarding,
    LogProject,
    LogRangePreset,
    LogResult,
    SeverityLevel,
    Team,
} from '@/types';

const props = defineProps<{
    onboarding: LogOnboarding;
    projects: LogProject[];
    filters: LogFilters;
    severityLevels: SeverityLevel[];
    /** Deferred: the service names seen for the projects in scope. */
    services?: string[];
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

/**
 * Onboarding is a fact about the team, not about the current window: it is
 * only ever shown when there are no projects, or when not one line has ever
 * been received. An empty filtered window stays an empty window.
 */
const onboarding = computed(() => props.onboarding.stage !== 'ready');

/**
 * While the reader is waiting for their first line, keep asking for it — the
 * page should flip to the stream on its own rather than needing a reload.
 */
const { start: startOnboardingPoll, stop: stopOnboardingPoll } = usePoll(
    10_000,
    { only: ['onboarding', 'logs', 'histogram'] },
    { autoStart: false },
);

/**
 * The one milestone in this product: a project that has never received a line
 * receives its first. It happens once and never again, so it gets marked.
 */
const firstLineLanded = ref(false);

let firstLineTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.onboarding.stage,
    (stage, previous) => {
        if (stage === 'no-logs') {
            startOnboardingPoll();

            return;
        }

        stopOnboardingPoll();

        if (previous === 'no-logs' && stage === 'ready') {
            firstLineLanded.value = true;

            firstLineTimer = setTimeout(() => {
                firstLineLanded.value = false;
                firstLineTimer = null;
            }, 12_000);
        }
    },
    { immediate: true },
);

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
        olderRows.value = [];
        olderCursor.value = null;
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

    return query;
};

const applyFilters = () => {
    const window = resolvedWindow();

    router.get(logsIndex(teamSlug.value).url, filterQuery(window), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

/**
 * Everything a step back has to restore.
 */
type FilterSnapshot = {
    project: string | null;
    service: string | null;
    severity: SeverityLevel[];
    search: string | null;
    from: string;
    to: string;
    range: LogRangePreset;
};

/**
 * How many steps back the viewer remembers. Deep enough to walk out of a
 * wrong turn, shallow enough that the array never becomes a memory concern.
 */
const HISTORY_LIMIT = 50;

/**
 * Filter history is deliberately local and deliberately not the browser's.
 *
 * Every filter change is a `replace: true` visit — that is what keeps the
 * browser's own back button pointed at the page the reader arrived from,
 * rather than burying it under fifty query strings. This stack is the undo
 * that replacing the entry takes away.
 */
const history = ref<FilterSnapshot[]>([]);

const currentSnapshot = (): FilterSnapshot => ({
    project: project.value,
    service: service.value,
    severity: [...severity.value],
    search: search.value,
    from: from.value,
    to: to.value,
    range: range.value,
});

const sameSnapshot = (a: FilterSnapshot, b: FilterSnapshot): boolean =>
    a.project === b.project &&
    a.service === b.service &&
    a.search === b.search &&
    a.from === b.from &&
    a.to === b.to &&
    a.range === b.range &&
    a.severity.length === b.severity.length &&
    a.severity.every((level) => b.severity.includes(level));

/**
 * Record the state a change is about to leave behind.
 *
 * Called before the mutation, never after, and only by the handlers that go
 * on to hit the server — a step that changed nothing the reader can see is a
 * step back that appears to do nothing.
 */
const pushHistory = () => {
    const snapshot = currentSnapshot();
    const previous = history.value[history.value.length - 1];

    if (previous && sameSnapshot(previous, snapshot)) {
        return;
    }

    history.value = [...history.value, snapshot].slice(-HISTORY_LIMIT);
};

const applySnapshot = (snapshot: FilterSnapshot) => {
    project.value = snapshot.project;
    service.value = snapshot.service;
    severity.value = [...snapshot.severity];
    search.value = snapshot.search;
    from.value = snapshot.from;
    to.value = snapshot.to;
    range.value = snapshot.range;

    applyFilters();
};

const canStepBack = computed(() => history.value.length > 0);

/**
 * Undo the last filter change. A preset window is re-resolved against now on
 * the way back, so stepping into "last hour" means the last hour, not the
 * hour it meant when you left it.
 */
const stepBack = () => {
    const previous = history.value[history.value.length - 1];

    if (!previous) {
        return;
    }

    history.value = history.value.slice(0, -1);

    applySnapshot(previous);
};

const isDefaultFilters = computed(
    () =>
        project.value === null &&
        service.value === null &&
        search.value === null &&
        severity.value.length === 0 &&
        range.value === DEFAULT_RANGE_PRESET,
);

/**
 * Back to the state the page opens on. Recorded like any other change, so a
 * reset is itself undoable.
 */
const resetFilters = () => {
    if (isDefaultFilters.value) {
        return;
    }

    pushHistory();

    project.value = null;
    service.value = null;
    severity.value = [];
    search.value = null;
    range.value = DEFAULT_RANGE_PRESET;

    applyFilters();
};

/*
 * Every handler below leaves early when it is handed the value it already
 * holds. That is not just a saved request: the search box debounces by 350ms,
 * so a step back taken mid-keystroke would otherwise be followed by the
 * pending emit re-recording the state it had just restored, and the next step
 * back would appear to do nothing.
 */

const onRange = (value: LogRangePreset) => {
    if (value === range.value) {
        return;
    }

    // Choosing "Custom range" only opens the two date fields; the window has
    // not moved yet, so there is nothing to step back to.
    if (value !== 'custom') {
        pushHistory();
    }

    range.value = value;

    if (value !== 'custom') {
        applyFilters();
    }
};

const onWindow = (window: { from: string; to: string }) => {
    if (window.from === from.value && window.to === to.value) {
        return;
    }

    pushHistory();

    from.value = window.from;
    to.value = window.to;
    applyFilters();
};

const onProject = (value: string | null) => {
    if (value === project.value) {
        return;
    }

    pushHistory();

    project.value = value;
    applyFilters();
};

const onService = (value: string | null) => {
    if (value === service.value) {
        return;
    }

    pushHistory();

    service.value = value;
    applyFilters();
};

const onSeverity = (value: SeverityLevel[]) => {
    if (
        value.length === severity.value.length &&
        value.every((level) => severity.value.includes(level))
    ) {
        return;
    }

    pushHistory();

    severity.value = value;
    applyFilters();
};

const onSearch = (value: string | null) => {
    if (value === search.value) {
        return;
    }

    pushHistory();

    search.value = value;
    applyFilters();
};

const expandedKey = ref<string | null>(null);
const liveTail = ref(false);

/**
 * The sidebar mark and the browser tab both report on tailing, so the state is
 * shared rather than local to this page.
 */
const { tailing: tailingStatus, noteUnseen } = useTailStatus();

useTailTabBadge();

watch(liveTail, (enabled) => {
    tailingStatus.value = enabled;
});
const tailRows = ref<LogEntry[]>([]);

/**
 * The pages read backwards from the first one, in the order they were asked
 * for. They are kept here rather than in the Inertia prop because reading
 * further back must not re-render the page: an Inertia visit would replace the
 * stream and take the reader's scroll position with it.
 */
const olderRows = ref<LogEntry[]>([]);

/**
 * Where the *next* older page starts. Null until one has been loaded, at which
 * point it supersedes the cursor the server sent with the first page.
 */
const olderCursor = ref<string | null>(null);

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
    ...olderRows.value,
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

            if (document.visibilityState === 'hidden') {
                noteUnseen(result.rows);
            }
        }
    } catch {
        liveTail.value = false;
    }
};

/**
 * Where the stream continues once the reader reaches the end of what is loaded.
 * The locally tracked cursor wins as soon as one older page has been read.
 */
const nextCursor = computed(
    () => olderCursor.value ?? props.logs?.nextCursor ?? null,
);

const olderRequest = useHttp<{ cursor: string }, LogResult>({ cursor: '' });

/**
 * Read one more page backwards and append it in place.
 *
 * Deliberately not an Inertia visit: the older page is added to the stream the
 * reader is already looking at, so the scroll position, the expanded row and
 * the tail buffer all survive.
 */
const loadOlder = async () => {
    const cursor = nextCursor.value;

    if (cursor === null || olderRequest.processing) {
        return;
    }

    olderRequest.cursor = cursor;

    try {
        const result = await olderRequest.get(
            logsOlder(teamSlug.value, {
                query: filterQuery({ from: from.value, to: to.value }),
            }).url,
        );

        if (result?.rows?.length) {
            olderRows.value = [...olderRows.value, ...result.rows];
        }

        olderCursor.value = result?.nextCursor ?? null;
    } catch {
        // The cursor is left where it was, so the button is still a retry.
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

const stopFirstLineTimer = () => {
    if (firstLineTimer !== null) {
        clearTimeout(firstLineTimer);
        firstLineTimer = null;
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

onBeforeUnmount(() => {
    stopTail();
    stopFirstLineTimer();
    tailingStatus.value = false;
});

const onScroll = (event: Event) => {
    scrolledDown.value = (event.target as HTMLElement).scrollTop > 32;
};

const toggleExpanded = (key: string) => {
    expandedKey.value = expandedKey.value === key ? null : key;
};

const shortcutsOpen = ref(false);

/**
 * `less`-style keys over the stream. Every one of them has a pointer
 * equivalent; this is a faster route, never the only one.
 */
const { cursor, reset: resetCursor } = useLogKeyboard({
    count: () => rows.value.length,
    toggle: (index) => {
        const entry = rows.value[index];

        if (entry) {
            toggleExpanded(entryKey(entry, index));
        }
    },
    collapse: () => {
        if (expandedKey.value === null) {
            return false;
        }

        expandedKey.value = null;

        return true;
    },
    focusSearch: () => {
        const field = document.querySelector<HTMLInputElement>(
            "[data-test='logs-search']",
        );

        field?.focus();
        field?.select();
    },
    openShortcuts: () => {
        shortcutsOpen.value = true;
    },
});

// A new result set invalidates the row the cursor was holding.
watch(() => props.filters, resetCursor);

/**
 * Keep the cursor row on screen when it moves off the top or bottom edge.
 */
watch(cursor, (index) => {
    if (index === null) {
        return;
    }

    void nextTick(() => {
        document
            .querySelector(`[data-log-index='${index}']`)
            ?.scrollIntoView({ block: 'nearest' });
    });
});

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

    <LogsShortcutsDialog v-model:open="shortcutsOpen" />

    <div class="flex min-h-0 flex-1 flex-col gap-4 p-4">
        <GetStartedPanel
            v-if="onboarding"
            :stage="props.onboarding.stage"
            :projects="projects"
            :team-slug="teamSlug"
        />

        <template v-else>
            <LogsToolbar
                :projects="projects"
                :services="services"
                :project="project"
                :service="service"
                :severity="severity"
                :search="search"
                :range="range"
                :from="from"
                :to="to"
                :live-tail="liveTail"
                :tailing="tailRequest.processing"
                :can-step-back="canStepBack"
                :can-reset="!isDefaultFilters"
                @step-back="stepBack"
                @reset="resetFilters"
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
                title="ClickHouse is thinking"
                :errors="[
                    'It could not answer this query in time. Narrow the window, or try again in a moment — nothing was lost.',
                ]"
            />

            <LogsHistogram
                :histogram="histogram"
                :severity="severity"
                @zoom="onZoom"
            />

            <div
                class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border bg-card"
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
                        v-if="firstLineLanded"
                        class="inline-flex animate-in items-center gap-1.5 font-medium text-severity-info duration-500 fade-in slide-in-from-left-2 motion-reduce:animate-none"
                        data-test="logs-first-line"
                    >
                        <span class="size-1.5 rounded-full bg-current" />
                        First line received. The pipe works.
                    </p>

                    <p
                        v-if="liveTail && tailPaused"
                        class="inline-flex items-center gap-1.5 rounded-full border border-severity-warn/40 bg-severity-warn/10 px-2 py-0.5 font-medium text-severity-warn"
                        data-test="logs-tail-paused"
                    >
                        <span class="size-1.5 rounded-full bg-current" />
                        Tail paused while you read
                    </p>
                    <p
                        v-else-if="liveTail"
                        class="inline-flex items-center gap-1.5 font-medium text-severity-info"
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

                    <button
                        type="button"
                        class="ml-auto hidden items-center gap-1.5 rounded px-1.5 py-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:inline-flex"
                        data-test="logs-shortcuts-open"
                        @click="shortcutsOpen = true"
                    >
                        <kbd
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded border border-input px-1 font-mono text-xs"
                        >
                            ?
                        </kbd>
                        Keyboard
                    </button>
                </header>

                <div
                    class="scrollbar-stream min-h-0 flex-1 overflow-auto"
                    @scroll="onScroll"
                >
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
                            This is not an empty window — the query never ran.
                            The lines are still in ClickHouse.
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
                        <div
                            class="flex w-40 items-end gap-1.5"
                            aria-hidden="true"
                        >
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
                                    active. Widen the window or clear a filter
                                    to see more.
                                </template>
                                <template v-else>
                                    Point an OTLP exporter at this project's
                                    ingest endpoint and its lines will show up
                                    here.
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
                            :data-log-index="index"
                            :expanded="expandedKey === entryKey(entry, index)"
                            :fresh="freshKeys.has(freshId(entry))"
                            :cursor="cursor === index"
                            @toggle="toggleExpanded(entryKey(entry, index))"
                        />

                        <div v-if="nextCursor" class="p-3 text-center">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-test="logs-load-older"
                                :disabled="olderRequest.processing"
                                @click="loadOlder"
                            >
                                <Spinner
                                    v-if="olderRequest.processing"
                                    class="size-4"
                                />
                                {{
                                    olderRequest.processing
                                        ? 'Loading older logs…'
                                        : 'Load older logs'
                                }}
                            </Button>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>
