<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, LogOut, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import Heading from '@/components/Heading.vue';
import LeaveTeamModal from '@/components/LeaveTeamModal.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit, index } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    teams: Team[];
};

defineProps<Props>();

const leaveTeamDialogOpen = ref(false);
const teamLeaving = ref<Team | null>(null);

const canLeaveTeam = (team: Team) => !team.isPersonal && team.role !== 'owner';

const openLeaveTeamDialog = (team: Team) => {
    teamLeaving.value = team;
    leaveTeamDialogOpen.value = true;
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Teams',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Teams" />

    <div class="flex flex-col gap-2">
        <Heading
            variant="small"
            title="Teams"
            description="Manage your teams and team memberships"
        >
            <template #actions>
                <CreateTeamModal>
                    <Button size="sm" data-test="teams-new-team-button">
                        <Plus /> New team
                    </Button>
                </CreateTeamModal>
            </template>
        </Heading>

        <div>
            <div
                v-for="team in teams"
                :key="team.id"
                data-test="team-row"
                class="flex min-h-14 items-center justify-between gap-4 border-b border-border/60 py-2.5"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-medium">{{
                            team.name
                        }}</span>
                        <span
                            v-if="team.isPersonal"
                            class="inline-flex h-5 shrink-0 items-center rounded-full px-2 text-[11px] font-medium text-foreground/80 ring-1 ring-border ring-inset"
                        >
                            Personal
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ team.roleLabel }}
                    </span>
                </div>

                <TooltipProvider>
                    <div class="flex items-center gap-2">
                        <Tooltip v-if="canLeaveTeam(team)">
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="team-leave-button"
                                    variant="ghost"
                                    size="sm"
                                    @click="openLeaveTeamDialog(team)"
                                >
                                    <LogOut class="h-4 w-4" />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Leave team</p>
                            </TooltipContent>
                        </Tooltip>

                        <Tooltip v-if="team.role === 'member'">
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="team-view-button"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="edit(team.slug)">
                                        <Eye class="h-4 w-4" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>View team</p>
                            </TooltipContent>
                        </Tooltip>

                        <Tooltip v-else>
                            <TooltipTrigger as-child>
                                <Button
                                    data-test="team-edit-button"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <Link :href="edit(team.slug)">
                                        <Pencil class="h-4 w-4" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                <p>Edit team</p>
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </TooltipProvider>
            </div>

            <p
                v-if="teams.length === 0"
                class="py-8 text-sm text-muted-foreground"
            >
                You don't belong to any teams yet.
            </p>
        </div>
    </div>

    <LeaveTeamModal v-model:open="leaveTeamDialogOpen" :team="teamLeaving" />
</template>
