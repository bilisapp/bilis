<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink, GitPullRequest, Plus, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import CreateFixJobModal from '@/components/CreateFixJobModal.vue';
import FixJobStatusBadge from '@/components/FixJobStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

/** `all` is the placeholder value a Select needs; the server sees no filter. */
const ALL = 'all';

const projectFilter = computed({
    get: () => props.filters.project ?? ALL,
    set: (value: string) => applyFilters({ project: value }),
});

const statusFilter = computed({
    get: () => props.filters.status ?? ALL,
    set: (value: string) => applyFilters({ status: value }),
});

function applyFilters(change: { project?: string; status?: string }) {
    const next = {
        project: change.project ?? props.filters.project ?? ALL,
        status: change.status ?? props.filters.status ?? ALL,
    };

    router.get(
        autofixIndex(props.teamSlug, {
            query: {
                project: next.project === ALL ? undefined : next.project,
                status: next.status === ALL ? undefined : next.status,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

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

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">Autofix</h1>
                <p class="max-w-prose text-sm text-muted-foreground">
                    Every attempt the agent has made at a production error, and
                    what came of it. A fix only ever reaches your repository as
                    a pull request — nothing is merged for you.
                </p>
            </div>

            <div v-if="hasRepository" class="flex items-center gap-2">
                <CreateFixJobModal
                    v-if="canCreateJob"
                    :team-slug="teamSlug"
                    :repositories="autofixRepositories"
                    :credentials="llmCredentials"
                >
                    <Button size="sm" data-test="autofix-new-job">
                        <Plus /> New job
                    </Button>
                </CreateFixJobModal>

                <Select v-model="projectFilter">
                    <SelectTrigger
                        size="sm"
                        class="w-40"
                        data-test="autofix-project-filter"
                    >
                        <SelectValue placeholder="Project" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">All projects</SelectItem>
                        <SelectItem
                            v-for="project in projects"
                            :key="project.slug"
                            :value="project.slug"
                        >
                            {{ project.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select v-model="statusFilter">
                    <SelectTrigger
                        size="sm"
                        class="w-40"
                        data-test="autofix-status-filter"
                    >
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Any status</SelectItem>
                        <SelectItem
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </header>

        <!--
          Nothing is connected yet: the honest next step is project settings,
          not a filter bar over an empty table.
        -->
        <div
            v-if="!hasRepository"
            class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 rounded-xl border bg-card px-6 py-16 text-center"
            data-test="autofix-not-configured"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <Wrench class="size-5" />
            </span>

            <div class="space-y-1">
                <p class="text-base font-semibold">
                    Connect a repository first
                </p>
                <p class="max-w-sm text-sm text-balance text-muted-foreground">
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

        <div
            v-else-if="!hasJobs"
            class="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 rounded-xl border bg-card px-6 py-16 text-center"
            data-test="autofix-empty"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <Wrench class="size-5" />
            </span>

            <div class="space-y-1">
                <p class="font-medium">No fix jobs yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Bilis scans for repeating errors every few minutes. The
                    first attempt appears here once one crosses the threshold —
                    or you can ask for a change yourself.
                </p>
            </div>

            <CreateFixJobModal
                v-if="canCreateJob"
                :team-slug="teamSlug"
                :repositories="autofixRepositories"
                :credentials="llmCredentials"
            >
                <Button size="sm" data-test="autofix-empty-new-job">
                    <Plus /> New job
                </Button>
            </CreateFixJobModal>
        </div>

        <div v-else class="overflow-hidden rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                >
                    <tr>
                        <th scope="col" class="px-4 py-2.5 font-medium">Job</th>
                        <th scope="col" class="px-4 py-2.5 font-medium">
                            Project
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-medium">
                            Status
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-medium">
                            Pull request
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-medium">
                            Started
                        </th>
                        <th scope="col" class="px-4 py-2.5 font-medium">
                            Finished
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    <tr
                        v-for="job in jobs.data"
                        :key="job.uuid"
                        class="transition-colors hover:bg-accent/40"
                        data-test="fix-job-row"
                    >
                        <td class="max-w-md px-4 py-3">
                            <Link
                                :href="autofixShow([teamSlug, job.uuid])"
                                class="block min-w-0 space-y-0.5"
                            >
                                <span
                                    class="flex min-w-0 items-center gap-1.5 font-medium"
                                >
                                    <span
                                        v-if="job.type === 'custom'"
                                        class="shrink-0 rounded-sm border px-1.5 py-0.5 text-xs font-medium tracking-[0.06em] text-muted-foreground uppercase"
                                        data-test="fix-job-custom-marker"
                                    >
                                        {{ job.typeLabel }}
                                    </span>
                                    <span class="truncate">{{
                                        job.title
                                    }}</span>
                                </span>
                                <span
                                    class="block truncate font-mono text-xs text-muted-foreground"
                                >
                                    <template v-if="job.type === 'custom'">
                                        {{ job.repository }}
                                    </template>
                                    <template v-else>
                                        {{ job.serviceName ?? job.repository }}
                                        · {{ job.fingerprint?.slice(0, 12) }}
                                    </template>
                                </span>
                            </Link>
                        </td>

                        <td class="px-4 py-3 text-muted-foreground">
                            {{ job.project.name }}
                        </td>

                        <td class="px-4 py-3">
                            <FixJobStatusBadge
                                :status="job.status"
                                :label="job.statusLabel"
                            />
                        </td>

                        <td class="px-4 py-3">
                            <a
                                v-if="job.prUrl"
                                :href="job.prUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 font-mono text-xs text-foreground underline underline-offset-4"
                                data-test="fix-job-pr-link"
                            >
                                <GitPullRequest class="size-3.5" />
                                #{{ job.prNumber }}
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ formatTime(job.createdAt) }}
                        </td>

                        <td
                            class="px-4 py-3 font-mono text-xs text-muted-foreground"
                        >
                            {{ formatTime(job.completedAt) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="jobs.lastPage > 1"
                class="flex items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground"
            >
                <span>
                    Page {{ jobs.currentPage }} of {{ jobs.lastPage }} ·
                    {{ jobs.total }} jobs
                </span>

                <div class="flex gap-2">
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
            </div>
        </div>
    </div>
</template>
