<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    FolderKanban,
    KeyRound,
    Plus,
    ScrollText,
} from '@lucide/vue';
import { computed } from 'vue';
import CreateProjectModal from '@/components/CreateProjectModal.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as logsIndex } from '@/routes/logs';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type {
    DashboardInvitation,
    LogOnboarding,
    LogProject,
    Team,
} from '@/types';

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    onboarding: LogOnboarding;
    firstProject: LogProject | null;
}>();

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: layoutProps.currentTeam
                    ? dashboard(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const stage = computed(() => props.onboarding.stage);
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="sr-only">Dashboard</h1>

        <!--
          The dashboard echoes whichever step the logs page is showing, so the
          two surfaces never disagree about how far along this team is.
        -->
        <Card
            class="max-w-2xl"
            data-test="dashboard-onboarding"
            :data-stage="stage"
        >
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <span
                        class="flex size-8 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <FolderKanban
                            v-if="stage === 'no-projects'"
                            class="size-4"
                        />
                        <ScrollText v-else class="size-4" />
                    </span>

                    <template v-if="stage === 'no-projects'">
                        Create a project
                    </template>
                    <template v-else-if="stage === 'no-logs'">
                        Send your first log
                    </template>
                    <template v-else> Your logs </template>
                </CardTitle>

                <CardDescription>
                    <template v-if="stage === 'no-projects'">
                        A project groups one application's logs and owns the API
                        keys your collector authenticates with. Nothing can land
                        in Bilis until one exists.
                    </template>
                    <template v-else-if="stage === 'no-logs'">
                        {{
                            firstProject
                                ? `${firstProject.name} is ready to receive logs.`
                                : 'Your projects are ready to receive logs.'
                        }}
                        The log viewer has copy-paste snippets for curl,
                        OpenTelemetry and Laravel.
                    </template>
                    <template v-else>
                        Lines are flowing. The viewer is where you search them,
                        filter by severity and tail them live.
                    </template>
                </CardDescription>
            </CardHeader>

            <CardContent class="flex flex-wrap gap-2">
                <template v-if="stage === 'no-projects'">
                    <CreateProjectModal :team-slug="teamSlug">
                        <Button data-test="dashboard-create-project">
                            <Plus /> Create a project
                        </Button>
                    </CreateProjectModal>

                    <Button variant="ghost" as-child>
                        <Link :href="projectsIndex(teamSlug)">
                            All projects
                        </Link>
                    </Button>
                </template>

                <template v-else>
                    <Button as-child data-test="dashboard-logs-link">
                        <Link :href="logsIndex(teamSlug)">
                            <ScrollText />
                            {{
                                stage === 'no-logs'
                                    ? 'Set up log shipping'
                                    : 'Open the log viewer'
                            }}
                            <ArrowRight />
                        </Link>
                    </Button>

                    <Button
                        v-if="stage === 'no-logs' && firstProject"
                        variant="outline"
                        as-child
                        data-test="dashboard-manage-keys"
                    >
                        <Link
                            :href="projectShow([teamSlug, firstProject.slug])"
                        >
                            <KeyRound /> Manage API keys
                        </Link>
                    </Button>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
