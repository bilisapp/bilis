<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { FolderKanban, KeyRound, Plus } from '@lucide/vue';
import { computed } from 'vue';
import CreateProjectModal from '@/components/CreateProjectModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/projects';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { PlanAllowance, ProjectSummary, Team } from '@/types';

defineProps<{
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
</script>

<template>
    <Head title="Projects" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="sr-only">Projects</h1>

        <div class="flex items-start justify-between gap-4">
            <p class="max-w-prose text-sm text-muted-foreground">
                A project is one application's stream of logs. Each project
                carries its own API keys, which is what your collector
                authenticates with.
            </p>

            <CreateProjectModal :team-slug="teamSlug" :allowance="planProjects">
                <Button data-test="projects-new-button">
                    <Plus /> New project
                </Button>
            </CreateProjectModal>
        </div>

        <div
            v-if="projects.length > 0"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            data-test="projects-list"
        >
            <Link
                v-for="project in projects"
                :key="project.id"
                :href="projectShow([teamSlug, project.slug])"
                class="rounded-xl transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                data-test="project-card"
            >
                <Card class="h-full gap-3 hover:border-ring/60">
                    <CardHeader>
                        <CardTitle class="truncate">
                            {{ project.name }}
                        </CardTitle>
                        <CardDescription class="font-mono text-xs">
                            {{ project.slug }}
                        </CardDescription>
                    </CardHeader>

                    <CardContent
                        class="flex items-center justify-between gap-2 text-sm text-muted-foreground"
                    >
                        <Badge variant="secondary" class="gap-1">
                            <KeyRound />
                            {{ project.apiKeysCount }}
                            {{ project.apiKeysCount === 1 ? 'key' : 'keys' }}
                        </Badge>

                        <span>Created {{ formatDate(project.createdAt) }}</span>
                    </CardContent>
                </Card>
            </Link>
        </div>

        <Card v-else class="items-center py-12" data-test="projects-empty">
            <CardContent
                class="flex max-w-md flex-col items-center gap-3 text-center"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <FolderKanban class="size-5" />
                </span>

                <div class="space-y-1">
                    <p class="font-medium">No projects yet</p>
                    <p class="text-sm text-muted-foreground">
                        Create a project, issue an API key, and point your
                        collector at the ingest endpoint. Logs show up in the
                        viewer straight away.
                    </p>
                </div>

                <CreateProjectModal
                    :team-slug="teamSlug"
                    :allowance="planProjects"
                >
                    <Button data-test="projects-empty-create">
                        <Plus /> Create your first project
                    </Button>
                </CreateProjectModal>
            </CardContent>
        </Card>
    </div>
</template>
