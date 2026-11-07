<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';
import BarSparkline from '@/components/BarSparkline.vue';
import IssueListRow from '@/components/IssueListRow.vue';
import IssuesToolbar from '@/components/IssuesToolbar.vue';
import KeyHint from '@/components/KeyHint.vue';
import PageHeader from '@/components/PageHeader.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useListCursor } from '@/composables/useListCursor';
import {
    ISSUE_PERIOD_LABEL,
    ISSUE_STATUS_FILTERS,
    formatCount,
    issueFilterQuery,
    issueHref,
} from '@/lib/issues';
import { index as issuesIndex } from '@/routes/issues';
import { index as projectsIndex } from '@/routes/projects';
import type {
    IssueFilters,
    IssueListResult,
    IssuePeriod,
    LogProject,
    Team,
} from '@/types';

const props = defineProps<{
    projects: LogProject[];
    filters: IssueFilters;
    periods: IssuePeriod[];
    /** Has this team ever had an issue? A fact about the team, not the window. */
    hasIssues: boolean;
    hasProjects: boolean;
    /** Deferred: the window's issues, merged with their status. */
    result?: IssueListResult;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Issues',
                href: layoutProps.currentTeam
                    ? issuesIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const issues = computed(() => props.result?.issues ?? []);

const canReset = computed(
    () =>
        props.filters.project !== null ||
        props.filters.service !== null ||
        props.filters.environment !== null ||
        props.filters.query !== null ||
        props.filters.status !== 'unresolved' ||
        props.filters.period !== '7d' ||
        props.filters.sort !== 'last_seen',
);

/**
 * Push a filter change into the URL — the query string is the state, so a
 * filtered list is a link and the back button walks the filters.
 */
function apply(
    changes: Partial<Record<keyof IssueFilters, string | number | null>>,
) {
    router.get(
        issuesIndex(teamSlug.value).url,
        issueFilterQuery(props.filters, changes),
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function reset() {
    router.get(
        issuesIndex(teamSlug.value).url,
        {},
        { preserveScroll: true, replace: true },
    );
}

/** The scope the list covers, printed above the title. */
const scope = computed(() => [
    props.projects.find((project) => project.slug === props.filters.project)
        ?.name ?? 'All projects',
    props.filters.environment ?? 'All environments',
]);

const statusWord = computed(() =>
    props.filters.status === 'all' ? 'issues' : props.filters.status,
);

/**
 * The window's volume, summed from the rows' own series.
 *
 * Every row's series covers the same buckets, so adding them column-wise is
 * the volume of what is listed. When the list runs to more than one page the
 * band says so: it is the busiest page, not the whole window.
 */
const volume = computed(() => {
    const series = issues.value.map((issue) => issue.series);
    const length = Math.max(0, ...series.map((values) => values.length));

    return Array.from({ length }, (_, index) =>
        series.reduce((sum, values) => sum + (values[index] ?? 0), 0),
    );
});

const PERIOD_HOURS: Record<string, number> = {
    '24h': 24,
    '7d': 168,
    '14d': 336,
    '30d': 720,
};

/** One bucket is an hour up to two days, a day beyond (IssueList::sparklineStep). */
const bucketIsHour = computed(
    () => (PERIOD_HOURS[props.filters.period] ?? 168) <= 48,
);

const volumeLabels = computed(() => {
    const step = (bucketIsHour.value ? 3600 : 86400) * 1000;
    const now = Date.now();
    const count = volume.value.length;
    const format = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        ...(bucketIsHour.value ? { hour: '2-digit', minute: '2-digit' } : {}),
    });

    return volume.value.map((_, index) =>
        format.format(new Date(now - (count - 1 - index) * step)),
    );
});

const volumePeak = computed(() => Math.max(0, ...volume.value));

/** The tallest bucket across every row, so the sparkline column shares a scale. */
const seriesMax = computed(() =>
    Math.max(1, ...issues.value.flatMap((issue) => issue.series)),
);

const statusOptions = computed(() =>
    ISSUE_STATUS_FILTERS.map((option) => ({
        ...option,
        count:
            option.value === props.filters.status && props.result
                ? props.result.total
                : null,
    })),
);

const pageCount = computed(() =>
    props.result
        ? Math.max(1, Math.ceil(props.result.total / props.result.perPage))
        : 1,
);

const { cursor, reset: resetCursor } = useListCursor({
    count: () => issues.value.length,
    open: (index) => {
        const issue = issues.value[index];

        if (issue) {
            router.visit(
                issueHref(teamSlug.value, issue.projectSlug, issue.fingerprint),
            );
        }
    },
    focusSearch: () => {
        const field = document.querySelector<HTMLInputElement>(
            "[data-test='issues-search']",
        );

        field?.focus();
        field?.select();
    },
});

watch(() => props.filters, resetCursor);

watch(cursor, (index) => {
    if (index === null) {
        return;
    }

    void nextTick(() => {
        document
            .querySelector(`[data-issue-index='${index}']`)
            ?.scrollIntoView({ block: 'nearest' });
    });
});
</script>

<template>
    <Head title="Issues" />

    <div class="flex flex-1 flex-col">
        <PageHeader title="Issues" :eyebrow="scope">
            <p data-test="issues-summary">
                <template v-if="result && !result.unavailable">
                    <span class="font-medium text-foreground"
                        >{{ result.total.toLocaleString() }}
                        {{ statusWord }}</span
                    >
                    · {{ ISSUE_PERIOD_LABEL[filters.period].toLowerCase() }}
                    <template v-if="result.regressed > 0">
                        ·
                        <span class="font-medium text-severity-error"
                            >{{ result.regressed }} regressed</span
                        >
                    </template>
                </template>
                <template v-else>
                    Exceptions grouped by type and top frame ·
                    {{ ISSUE_PERIOD_LABEL[filters.period].toLowerCase() }}
                </template>
            </p>

            <template #actions>
                <SegmentedControl
                    :model-value="filters.status"
                    :options="statusOptions"
                    aria-label="Status"
                    data-test-prefix="issues-status"
                    @update:model-value="apply({ status: $event })"
                />
            </template>
        </PageHeader>

        <div class="px-4 pb-5 md:px-10">
            <IssuesToolbar
                :projects="projects"
                :project="filters.project"
                :services="result?.services ?? []"
                :service="filters.service"
                :environments="result?.environments ?? []"
                :environment="filters.environment"
                :period="filters.period"
                :periods="periods"
                :sort="filters.sort"
                :query="filters.query"
                :can-reset="canReset"
                @update:project="apply({ project: $event })"
                @update:service="apply({ service: $event })"
                @update:environment="apply({ environment: $event })"
                @update:period="apply({ period: $event })"
                @update:sort="apply({ sort: $event })"
                @update:query="apply({ query: $event })"
                @reset="reset"
            />
        </div>

        <!--
          The volume band: the listed issues' events per bucket, so a spike is
          visible before a single row is read.
        -->
        <section
            v-if="issues.length > 0 && volumePeak > 0"
            class="flex flex-col gap-2 px-4 pb-5 md:px-10"
            data-test="issues-volume"
        >
            <div
                class="flex items-baseline justify-between gap-4 text-xs text-muted-foreground"
            >
                <p>
                    <span class="font-medium text-foreground"
                        >Events per {{ bucketIsHour ? 'hour' : 'day' }}</span
                    >
                    ·
                    {{
                        pageCount > 1
                            ? 'this page of issues'
                            : 'the issues below'
                    }}
                </p>
                <p class="font-mono">peak {{ formatCount(volumePeak) }}</p>
            </div>
            <BarSparkline
                :values="volume"
                :labels="volumeLabels"
                tone="error"
                unit="events"
                :height="56"
            />
        </section>

        <section class="flex flex-1 flex-col border-t" data-test="issues-list">
            <header
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 border-b column-header px-4 py-2.5 md:grid-cols-[minmax(0,1fr)_10.5rem_4rem_4rem_6rem] md:px-10"
            >
                <span>Issue</span>
                <span class="hidden md:block">{{
                    ISSUE_PERIOD_LABEL[filters.period]
                }}</span>
                <span class="text-right">Events</span>
                <span class="hidden text-right md:block">Users</span>
                <span class="hidden text-right md:block">Last seen</span>
            </header>

            <div v-if="!result" class="flex flex-col gap-3 px-4 py-4 md:px-10">
                <Skeleton v-for="index in 6" :key="index" class="h-16 w-full" />
            </div>

            <p
                v-else-if="result.unavailable"
                class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                data-test="issues-unavailable"
            >
                Issue storage is busy and could not answer in time. Nothing is
                lost — retry in a moment.
            </p>

            <!--
              Three empty states, each a different fact: no project to report
              into, a team that has never sent an exception, and a window with
              nothing in it.
            -->
            <div
                v-else-if="!hasProjects"
                class="flex flex-col gap-2 px-4 py-8 md:px-10"
                data-test="issues-empty-projects"
            >
                <p class="text-sm font-medium">No projects yet</p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Issues group the exceptions a project reports.
                    <Link
                        :href="projectsIndex(teamSlug).url"
                        class="underline underline-offset-4 hover:text-foreground"
                    >
                        Create a project
                    </Link>
                    to get an API key and a DSN.
                </p>
            </div>

            <div
                v-else-if="!hasIssues"
                class="flex flex-col gap-2 px-4 py-8 md:px-10"
                data-test="issues-empty-never"
            >
                <p class="text-sm font-medium">No exceptions yet</p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    An issue is every occurrence of one exception, grouped by
                    its type and stack. Point an error-reporting SDK at a
                    project's DSN, or log exceptions at ERROR with
                    <code class="font-mono">exception.type</code> and
                    <code class="font-mono">exception.stacktrace</code> — the
                    project page has the setup for both.
                </p>
            </div>

            <p
                v-else-if="issues.length === 0"
                class="px-4 py-8 text-sm text-muted-foreground md:px-10"
                data-test="issues-empty-window"
            >
                No {{ filters.status === 'all' ? '' : filters.status }} issues
                match in this window.
            </p>

            <div v-else>
                <IssueListRow
                    v-for="(issue, index) in issues"
                    :key="`${issue.projectId}:${issue.fingerprint}`"
                    :issue="issue"
                    :team-slug="teamSlug"
                    :series-max="seriesMax"
                    :data-issue-index="index"
                    :cursor="cursor === index"
                />
            </div>

            <footer
                v-if="result && !result.unavailable"
                class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground md:px-10"
            >
                <span>
                    <template v-if="pageCount > 1">
                        Page {{ result.page }} of {{ pageCount }} ·
                    </template>
                    {{ result.total.toLocaleString() }}
                    {{ result.total === 1 ? 'issue' : 'issues' }} · grouped by
                    exception type and top frame
                    <template v-if="result.truncated">
                        · showing the busiest issues only; narrow the window to
                        see the rest
                    </template>
                </span>
                <div class="flex items-center gap-4">
                    <div class="hidden items-center gap-3.5 sm:flex">
                        <KeyHint :keys="['j', 'k']" label="move" />
                        <KeyHint :keys="['↵']" label="open" />
                        <KeyHint :keys="['/']" label="search" />
                    </div>
                    <div v-if="pageCount > 1" class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="result.page <= 1"
                            data-test="issues-previous"
                            @click="apply({ page: result.page - 1 })"
                        >
                            Previous
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="result.page >= pageCount"
                            data-test="issues-next"
                            @click="apply({ page: result.page + 1 })"
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </footer>
        </section>
    </div>
</template>
