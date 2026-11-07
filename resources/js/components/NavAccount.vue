<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, LogOut, Plus, Settings } from '@lucide/vue';
import { computed } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { getInitials } from '@/composables/useInitials';
import { useTeamSwitch } from '@/composables/useTeamSwitch';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';

/**
 * "Who am I acting as", answered once at the foot of the rail.
 *
 * Team and account used to be two stacked switchers with a chevron each. Both
 * are switched rarely and both answer the same question, so they share one
 * card and one menu: the team you are in (its name, your address under it),
 * then the teams you can move to, then your settings and the way out.
 */
const page = usePage();
const user = computed(() => page.props.auth.user);
const { currentTeam, teams, switchTeam } = useTeamSwitch();
const { isMobile, state } = useSidebar();

const tileLabel = computed(() =>
    getInitials(currentTeam.value?.name ?? user.value.name).slice(0, 2),
);

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="rounded-lg bg-sidebar-primary/60 ring-1 ring-sidebar-border group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:ring-0 hover:bg-sidebar-primary data-[state=open]:bg-sidebar-primary"
                        data-test="sidebar-menu-button"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-semibold text-primary-foreground"
                            aria-hidden="true"
                        >
                            {{ tileLabel }}
                        </span>
                        <span
                            class="grid min-w-0 flex-1 text-left leading-tight"
                        >
                            <span
                                class="truncate text-sm font-semibold text-sidebar-accent-foreground"
                                data-test="team-switcher-trigger"
                            >
                                {{ currentTeam?.name ?? user.name }}
                            </span>
                            <span
                                class="truncate text-xs text-sidebar-foreground"
                            >
                                {{ user.email }}
                            </span>
                        </span>
                        <ChevronsUpDown class="ml-auto size-4 opacity-60" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-60 rounded-lg"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'top'
                    "
                    align="start"
                    :side-offset="6"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground">
                        Teams
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="team in teams"
                        :key="team.id"
                        data-test="team-switcher-item"
                        class="cursor-pointer gap-2"
                        @click="switchTeam(team)"
                    >
                        <span class="truncate">{{ team.name }}</span>
                        <Check
                            v-if="currentTeam?.id === team.id"
                            class="ml-auto size-4"
                        />
                    </DropdownMenuItem>
                    <CreateTeamModal>
                        <DropdownMenuItem
                            data-test="team-switcher-new-team"
                            class="cursor-pointer gap-2"
                            @select.prevent
                        >
                            <Plus class="size-4" />
                            <span class="text-muted-foreground">New team</span>
                        </DropdownMenuItem>
                    </CreateTeamModal>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel class="font-normal">
                        <span class="block truncate text-sm font-medium">{{
                            user.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ user.email }}</span
                        >
                    </DropdownMenuLabel>
                    <DropdownMenuGroup>
                        <DropdownMenuItem :as-child="true">
                            <Link
                                class="block w-full cursor-pointer"
                                :href="edit()"
                                prefetch
                            >
                                <Settings class="mr-2 size-4" />
                                Settings
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem :as-child="true">
                            <Link
                                class="block w-full cursor-pointer"
                                :href="logout()"
                                as="button"
                                data-test="logout-button"
                                @click="handleLogout"
                            >
                                <LogOut class="mr-2 size-4" />
                                Log out
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
