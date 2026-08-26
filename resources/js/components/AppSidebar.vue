<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FolderKanban, LayoutGrid, Palette, ScrollText } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, styleguide } from '@/routes';
import { index as logsIndex } from '@/routes/logs';
import { index as projectsIndex } from '@/routes/projects';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const logsUrl = computed(() =>
    page.props.currentTeam ? logsIndex(page.props.currentTeam.slug).url : '/',
);

const projectsUrl = computed(() =>
    page.props.currentTeam
        ? projectsIndex(page.props.currentTeam.slug).url
        : '/',
);

/**
 * Logs sits above Projects on purpose: the viewer is the surface people come
 * back to, projects are the thing you set up once.
 */
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Logs',
        href: logsUrl.value,
        icon: ScrollText,
    },
    {
        title: 'Projects',
        href: projectsUrl.value,
        icon: FolderKanban,
    },
]);

const resourceNavItems: NavItem[] = [
    {
        title: 'Styleguide',
        href: styleguide(),
        icon: Palette,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4">
            <NavMain :items="mainNavItems" />
            <NavMain label="Resources" :items="resourceNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
