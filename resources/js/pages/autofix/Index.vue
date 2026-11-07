<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, GitPullRequest, Plus, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import CreateFixJobModal from '@/components/CreateFixJobModal.vue';
import FilterSelect from '@/components/FilterSelect.vue';
import FixJobStatusBadge from '@/components/FixJobStatusBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { index as autofixIndex, show as autofixShow } from '@/routes/autofix';
import { index as projectsIndex } from '@/routes/projects';
import type {
    AutofixRepositoryOption,
    FixJobFilters,
    FixJobPage,
    FixJobStatusOption,
    LogProject,
    TeamLlmCredential,
} from '@/types';

const props = defineProps<{
    teamSlug: string;
    jobs: FixJobPage;
    projects: LogProject[];
    statuses: FixJobStatusOption[];
    filters: FixJobFilters;
    hasRepository: boolean;
    autofixRepositories: AutofixRepositoryOption[];
    llmCredentials: TeamLlmCredential[];
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: { slug: string } | null }) => ({
        breadcrumbs: [
            {
                title: 'Autofix',
                href: layoutProps.currentTeam
                    ? autofixIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

/**
 * Push a filter into the URL; `null` is "all", which the server sees as no
 * filter at all. Changing a filter starts again from the first page.
 */
function applyFilters(change: {
    project?: string | null;
    status?: string | null;
}) {
    const next = {
        project:
            change.project !== undefined
                ? change.project
                : props.filters.project,
        status:
            change.status !== undefined ? change.status : props.filters.status,
    };

    router.get(
        autofixIndex(props.teamSlug, {
            query: {
                project: next.project ?? undefined,
                status: next.status ?? undefined,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const projectOptions = computed(() =>
    props.projects.map((project) => ({
        value: project.slug,
        label: project.name,
    })),
);

const statusOptions = computed(() =>
    props.statuses.map((status) => ({
        value: status.value,
        label: status.label,
    })),
);

/** The scope the list covers, printed above the title. */
const scope = computed(() => [
    props.projects.find((project) => project.slug === props.filters.project)
        ?.name ?? 'All projects',
    props.statuses.find((status) => status.value === props.filters.status)
        ?.label ?? 'Any status',
]);

const canReset = computed(
    () => props.filters.project !== null || props.filters.status !== null,
);

/** One template for the column header and every row, so the lanes align. */
const COLUMNS =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 md:grid-cols-[minmax(0,1fr)_9rem_8.5rem_6rem_7.5rem_7.5rem]';

const formatTime = (iso: string | null): string => {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    return Number.isNaN(parsed.getTime())
        ? '—'
        : new Intl.DateTimeFormat(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(parsed);
};

const hasJobs = computed(() => props.jobs.data.length > 0);

/** A job can only be spawned against a repository that opted in. */
const canCreateJob = computed(() => props.autofixRepositories.length > 0);

function goToPage(page: number) {
    router.get(
        autofixIndex(props.teamSlug, {
            query: {
                project: props.filters.project ?? undefined,
                status: props.filters.status ?? undefined,
                page,
            },
        }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Autofix" />

    <div class="flex flex-1 flex-col">
        <PageHeader title="Autofix" :eyebrow="scope">
            <p data-test="autofix-summary">
                <template v-if="hasRepository && jobs.total > 0">
                    <span class="font-medium text-foreground"
                        >{{ jobs.total.toLocaleString() }}
                        {{ jobs.total === 1 ? 'job' : 'jobs' }}</span
                    >
                    ·
                </template>
                Every attempt the agent has made, and what came of it. A fix
                only reaches your repository as a pull request — nothing is
                merged for you.
            </p>

            <template v-if="hasRepository && canCreateJob" #actions>
                <CreateFixJobModal
                    :team-slug="teamSlug"
                    :repositories="autofixRepositories"
                    :credentials="llmCredentials"
                >
                    <Button data-test="autofix-new-job">
                        <Plus /> New job
                    </Button>
                </CreateFixJobModal>
            </template>
        </PageHeader>

        <!--
          Nothing is connected yet: the honest next step is project settings,
          not a filter bar over an empty list.
        -->
        <div
            v-if="!hasRepository"
            class="flex flex-col items-start gap-4 border-t px-4 py-8 md:px-10"
            data-test="autofix-not-configured"
        >
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-foreground">
                    Connect a repository first
                </p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Autofix reads the errors already in your logs, then opens a
                    pull request against the repository that project ships from.
                    Until one is connected, there is nothing for it to work on.
                </p>
            </div>

            <Button as-child data-test="autofix-connect-cta">
                <Link :href="projectsIndex(teamSlug)"
                    >Open project settings</Link
                >
            </Button>
        </div>

        <template v-else>
            <div class="px-4 pb-5 md:px-10">
                <div
                    class="flex flex-wrap items-center gap-2"
                    data-test="autofix-toolbar"
                >
                    <FilterSelect
                        v-if="projects.length > 1"
                        id="autofix-project"
                        label="Project"
                        all-label="All projects"
                        data-test="autofix-project-filter"
                        :model-value="filters.project"
                        :options="projectOptions"
                        @update:model-value="applyFilters({ project: $event })"
                    />
                    <FilterSelect
                        id="autofix-status"
                        label="Status"
                        all-label="Any status"
                        all-short="Any"
                        data-test="autofix-status-filter"
                        :model-value="filters.status"
                        :options="statusOptions"
                        @update:model-value="applyFilters({ status: $event })"
                    />
                    <Button
                        v-if="canReset"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-9 text-muted-foreground"
                        data-test="autofix-reset"
                        @click="applyFilters({ project: null, status: null })"
                    >
                        <RotateCcw class="size-3.5" />
                        Reset
                    </Button>
                </div>
            </div>

            <section
                class="flex flex-1 flex-col border-t"
                data-test="autofix-list"
            >
                <header
                    :class="[
                        COLUMNS,
                        'border-b column-header px-4 py-2.5 md:px-10',
                    ]"
                >
                    <span>Job</span>
                    <span class="hidden md:block">Project</span>
                    <span>Status</span>
                    <span class="hidden md:block">Pull request</span>
                    <span class="hidden text-right md:block">Started</span>
                    <span class="hidden text-right md:block">Finished</span>
                </header>

                <div
                    v-if="!hasJobs"
                    class="flex flex-col items-start gap-4 px-4 py-8 md:px-10"
                    data-test="autofix-empty"
                >
                    <div class="flex flex-col gap-2">
                        <p class="text-sm font-medium text-foreground">
                            No fix jobs yet
                        </p>
                        <p class="max-w-prose text-sm text-muted-foreground">
                            Bilis scans for repeating errors every few minutes.
                            The first attempt appears here once one crosses the
                            threshold — or you can ask for a change yourself.
                        </p>
                    </div>

                    <CreateFixJobModal
                        v-if="canCreateJob"
                        :team-slug="teamSlug"
                        :repositories="autofixRepositories"
                        :credentials="llmCredentials"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            data-test="autofix-empty-new-job"
                        >
                            <Plus /> New job
                        </Button>
                    </CreateFixJobModal>
                </div>

                <div v-else>
                    <div
                        v-for="job in jobs.data"
                        :key="job.uuid"
                        :class="[
                            COLUMNS,
                            'relative border-b border-border/70 px-4 py-4 transition-colors hover:bg-muted/50 md:px-10',
                        ]"
                        data-test="fix-job-row"
                    >
                        <!--
                          The title's link covers the whole row; the pull
                          request link sits above it so both stay clickable.
                        -->
                        <Link
                            :href="autofixShow([teamSlug, job.uuid])"
                            class="flex min-w-0 flex-col gap-1 after:absolute after:inset-0 focus-visible:outline-none"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    v-if="job.type === 'custom'"
                                    class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                                    data-test="fix-job-custom-marker"
                                >
                                    {{ job.typeLabel }}
                                </span>
                                <span
                                    class="truncate text-sm font-semibold text-foreground"
                                    :title="job.title"
                                    >{{ job.title }}</span
                                >
                            </span>
                            <span
                                class="flex min-w-0 items-center gap-1.5 font-mono text-xs text-muted-foreground"
                            >
                                <template v-if="job.type === 'custom'">
                                    <span class="truncate">{{
                                        job.repository
                                    }}</span>
                                </template>
                                <template v-else>
                                    <span class="truncate text-foreground/75">{{
                                        job.serviceName ?? job.repository
                                    }}</span>
                                    <span class="text-input" aria-hidden="true"
                                        >·</span
                                    >
                                    <span class="shrink-0">{{
                                        job.fingerprint?.slice(0, 12)
                                    }}</span>
                                </template>
                                <span
                                    class="text-input md:hidden"
                                    aria-hidden="true"
                                    >·</span
                                >
                                <span class="shrink-0 font-sans md:hidden">{{
                                    job.project.name
                                }}</span>
                            </span>
                        </Link>

                        <span
                            class="hidden truncate text-sm text-foreground/80 md:block"
                        >
                            {{ job.project.name }}
                        </span>

                        <span>
                            <FixJobStatusBadge
                                :status="job.status"
                                :label="job.statusLabel"
                            />
                        </span>

                        <span class="hidden md:block">
                            <a
                                v-if="job.prUrl"
                                :href="job.prUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="relative z-10 inline-flex items-center gap-1.5 font-mono text-xs text-foreground underline-offset-4 hover:underline"
                                data-test="fix-job-pr-link"
                            >
                                <GitPullRequest class="size-3.5" />
                                #{{ job.prNumber }}
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else class="text-sm text-muted-foreground"
                                >—</span
                            >
                        </span>

                        <span
                            class="hidden text-right font-mono text-xs text-muted-foreground tabular-nums md:block"
                        >
                            {{ formatTime(job.createdAt) }}
                        </span>

                        <span
                            class="hidden text-right font-mono text-xs text-muted-foreground tabular-nums md:block"
                        >
                            {{ formatTime(job.completedAt) }}
                        </span>
                    </div>
                </div>

                <footer
                    v-if="hasJobs"
                    class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground md:px-10"
                >
                    <span>
                        <template v-if="jobs.lastPage > 1">
                            Page {{ jobs.currentPage }} of {{ jobs.lastPage }} ·
                        </template>
                        {{ jobs.total.toLocaleString() }}
                        {{ jobs.total === 1 ? 'job' : 'jobs' }} · newest first
                    </span>

                    <div v-if="jobs.lastPage > 1" class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="jobs.currentPage <= 1"
                            @click="goToPage(jobs.currentPage - 1)"
                        >
                            Newer
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="jobs.currentPage >= jobs.lastPage"
                            @click="goToPage(jobs.currentPage + 1)"
                        >
                            Older
                        </Button>
                    </div>
                </footer>
            </section>
        </template>
    </div>
</template>
