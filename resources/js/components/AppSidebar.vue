<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    FolderKanban,
    LayoutGrid,
    Palette,
    ScrollText,
    Wrench,
} from '@lucide/vue';
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
import { index as autofixIndex } from '@/routes/autofix';
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

const autofixUrl = computed(() =>
    page.props.currentTeam
        ? autofixIndex(page.props.currentTeam.slug).url
        : '/',
);

const projectsUrl = computed(() =>
    page.props.currentTeam
        ? projectsIndex(page.props.currentTeam.slug).url
        : '/',
);

/**
 * Logs sits above Projects on purpose: the viewer is the surface people come
 * back to, projects are the thing you set up once. Autofix comes last: it is
 * downstream of both, and reads as a consequence of the logs rather than a
 * place you go first.
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
    {
        title: 'Autofix',
        href: autofixUrl.value,
        icon: Wrench,
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
        <!--
          The brand stands alone up here. It used to share the header with the
          team switcher, which set the team name in a heavier style directly
          under the product name — so the rail read as if the team were the
          title and Bilis its caption.
        -->
        <SidebarHeader class="pb-1">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="hover:bg-sidebar-accent/60"
                    >
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-5">
            <NavMain :items="mainNavItems" />
            <NavMain label="Resources" :items="resourceNavItems" />
        </SidebarContent>

        <!--
          Team and account belong together: both answer "who am I acting as",
          and both are switched rarely. Pairing them at the foot also stops the
          rail's lower half from reading as leftover space.
        -->
        <SidebarFooter class="gap-1 border-t border-sidebar-border/60 pt-2">
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
