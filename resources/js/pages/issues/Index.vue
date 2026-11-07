<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, watch } from 'vue';
import IssueListRow from '@/components/IssueListRow.vue';
import IssuesToolbar from '@/components/IssuesToolbar.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useListCursor } from '@/composables/useListCursor';
import { ISSUE_PERIOD_LABEL, issueFilterQuery, issueHref } from '@/lib/issues';
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

    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
        <IssuesToolbar
            :projects="projects"
            :project="filters.project"
            :services="result?.services ?? []"
            :service="filters.service"
            :environments="result?.environments ?? []"
            :environment="filters.environment"
            :status="filters.status"
            :period="filters.period"
            :periods="periods"
            :sort="filters.sort"
            :query="filters.query"
            :can-reset="canReset"
            @update:project="apply({ project: $event })"
            @update:service="apply({ service: $event })"
            @update:environment="apply({ environment: $event })"
            @update:status="apply({ status: $event })"
            @update:period="apply({ period: $event })"
            @update:sort="apply({ sort: $event })"
            @update:query="apply({ query: $event })"
            @reset="reset"
        />

        <section class="flex flex-col rounded-lg border bg-card">
            <header
                class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 border-b px-4 py-3 text-xs text-muted-foreground md:grid-cols-[minmax(0,1fr)_7rem_4.5rem_4.5rem_6.5rem]"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    Issues
                    <span
                        v-if="result && !result.unavailable"
                        class="ml-1 font-mono font-normal text-muted-foreground tabular-nums"
                    >
                        {{ result.total }}
                    </span>
                </h2>
                <span class="hidden md:block">{{
                    ISSUE_PERIOD_LABEL[filters.period]
                }}</span>
                <span class="text-right md:text-left">Events</span>
                <span class="hidden md:block">Users</span>
                <span class="hidden md:block">Last · age</span>
            </header>

            <div v-if="!result" class="flex flex-col gap-2 p-4">
                <Skeleton v-for="index in 6" :key="index" class="h-14 w-full" />
            </div>

            <p
                v-else-if="result.unavailable"
                class="p-6 text-sm text-muted-foreground"
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
                class="flex flex-col gap-2 p-6"
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
                class="flex flex-col gap-2 p-6"
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
                class="p-6 text-sm text-muted-foreground"
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
                    :data-issue-index="index"
                    :cursor="cursor === index"
                />
            </div>

            <footer
                v-if="result && (pageCount > 1 || result.truncated)"
                class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs text-muted-foreground"
            >
                <span>
                    Page {{ result.page }} of {{ pageCount }}
                    <template v-if="result.truncated">
                        · showing the busiest issues only; narrow the window to
                        see the rest
                    </template>
                </span>
                <div class="flex gap-2">
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
            </footer>
        </section>
    </div>
</template>
