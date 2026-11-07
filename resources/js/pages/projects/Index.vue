<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, nextTick, watch } from 'vue';
import CreateProjectModal from '@/components/CreateProjectModal.vue';
import KeyHint from '@/components/KeyHint.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useListCursor } from '@/composables/useListCursor';
import { formatDate } from '@/lib/projects';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { PlanAllowance, ProjectSummary, Team } from '@/types';

const props = defineProps<{
    projects: ProjectSummary[];
    planProjects: PlanAllowance;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Projects',
                href: layoutProps.currentTeam
                    ? projectsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

/** One grid for the header and every row, so the lanes line up. */
const LANES =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 md:grid-cols-[minmax(0,1fr)_5rem_8rem]';

const keyTotal = computed(() =>
    props.projects.reduce((total, project) => total + project.apiKeysCount, 0),
);

/** The Free plan's number, only when this install publishes one. */
const hasAllowance = computed(() => props.planProjects.limit > 0);

const { cursor } = useListCursor({
    count: () => props.projects.length,
    open: (index) => {
        const project = props.projects[index];

        if (project) {
            router.visit(projectShow([teamSlug.value, project.slug]));
        }
    },
});

watch(cursor, (index) => {
    if (index === null) {
        return;
    }

    void nextTick(() => {
        document
            .querySelector(`[data-project-index='${index}']`)
            ?.scrollIntoView({ block: 'nearest' });
    });
});
</script>

<template>
    <Head title="Projects" />

    <div class="flex flex-1 flex-col">
        <PageHeader
            title="Projects"
            :eyebrow="[page.props.currentTeam?.name ?? 'Team']"
        >
            <p data-test="projects-summary">
                <template v-if="projects.length > 0">
                    <span class="font-medium text-foreground"
                        >{{ projects.length }}
                        {{
                            projects.length === 1 ? 'project' : 'projects'
                        }}</span
                    >
                    · {{ keyTotal }} API {{ keyTotal === 1 ? 'key' : 'keys' }}
                    <template v-if="hasAllowance">
                        · {{ planProjects.used }} of {{ planProjects.limit }} on
                        the Free plan
                    </template>
                </template>
                <template v-else>
                    One application's stream of logs, traces and errors, and the
                    API keys it sends them with.
                </template>
            </p>

            <template #actions>
                <CreateProjectModal
                    :team-slug="teamSlug"
                    :allowance="planProjects"
                >
                    <Button data-test="projects-new-button">
                        <Plus /> New project
                    </Button>
                </CreateProjectModal>
            </template>
        </PageHeader>

        <section class="flex flex-1 flex-col border-t">
            <header
                :class="
                    cn(LANES, 'border-b column-header px-4 py-2.5 md:px-10')
                "
            >
                <span>Project</span>
                <span class="text-right">Keys</span>
                <span class="hidden text-right md:block">Created</span>
            </header>

            <div v-if="projects.length > 0" data-test="projects-list">
                <Link
                    v-for="(project, index) in projects"
                    :key="project.id"
                    :href="projectShow([teamSlug, project.slug])"
                    :class="
                        cn(
                            LANES,
                            'border-b border-border/70 px-4 py-4 transition-colors hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:outline-none md:px-10',
                            cursor === index &&
                                'bg-muted/70 ring-1 ring-ring/50 ring-inset',
                        )
                    "
                    :data-project-index="index"
                    :data-cursor="cursor === index ? 'true' : undefined"
                    data-test="project-card"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <span
                            class="truncate text-sm font-semibold text-foreground"
                        >
                            {{ project.name }}
                        </span>
                        <span
                            class="truncate font-mono text-xs text-muted-foreground"
                        >
                            {{ project.slug }}
                        </span>
                    </div>

                    <span
                        :class="
                            cn(
                                'text-right font-mono text-sm tabular-nums',
                                project.apiKeysCount === 0
                                    ? 'text-muted-foreground'
                                    : 'font-medium text-foreground',
                            )
                        "
                    >
                        {{ project.apiKeysCount }}
                    </span>

                    <span
                        class="hidden text-right text-sm text-foreground/75 md:block"
                    >
                        {{ formatDate(project.createdAt) }}
                    </span>
                </Link>
            </div>

            <div
                v-else
                class="flex flex-col items-start gap-3 px-4 py-8 md:px-10"
                data-test="projects-empty"
            >
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-foreground">
                        No projects yet
                    </p>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        Create a project, issue an API key, and point your
                        collector at the ingest endpoint. Logs show up in the
                        viewer straight away.
                    </p>
                </div>

                <CreateProjectModal
                    :team-slug="teamSlug"
                    :allowance="planProjects"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="projects-empty-create"
                    >
                        <Plus /> Create your first project
                    </Button>
                </CreateProjectModal>
            </div>

            <footer
                v-if="projects.length > 0"
                class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-xs text-muted-foreground md:px-10"
            >
                <span>
                    {{ projects.length }}
                    {{ projects.length === 1 ? 'project' : 'projects' }} · each
                    key decides which project its data lands in
                </span>
                <div class="hidden items-center gap-3.5 sm:flex">
                    <KeyHint :keys="['j', 'k']" label="move" />
                    <KeyHint :keys="['↵']" label="open" />
                </div>
            </footer>
        </section>
    </div>
</template>
