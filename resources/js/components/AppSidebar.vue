<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BellRing,
    BookOpen,
    Bug,
    ChartLine,
    FolderKanban,
    LayoutGrid,
    ScrollText,
    Waypoints,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavAccount from '@/components/NavAccount.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as alertsIndex } from '@/routes/alerts';
import { index as autofixIndex } from '@/routes/autofix';
import { index as docsIndex } from '@/routes/docs';
import { index as issuesIndex } from '@/routes/issues';
import { index as logsIndex } from '@/routes/logs';
import { index as metricsIndex } from '@/routes/metrics';
import { index as projectsIndex } from '@/routes/projects';
import { index as tracesIndex } from '@/routes/traces';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const logsUrl = computed(() =>
    page.props.currentTeam ? logsIndex(page.props.currentTeam.slug).url : '/',
);

const issuesUrl = computed(() =>
    page.props.currentTeam ? issuesIndex(page.props.currentTeam.slug).url : '/',
);

const alertsUrl = computed(() =>
    page.props.currentTeam ? alertsIndex(page.props.currentTeam.slug).url : '/',
);

const autofixUrl = computed(() =>
    page.props.currentTeam
        ? autofixIndex(page.props.currentTeam.slug).url
        : '/',
);

const tracesUrl = computed(() =>
    page.props.currentTeam ? tracesIndex(page.props.currentTeam.slug).url : '/',
);

const metricsUrl = computed(() =>
    page.props.currentTeam
        ? metricsIndex(page.props.currentTeam.slug).url
        : '/',
);

const projectsUrl = computed(() =>
    page.props.currentTeam
        ? projectsIndex(page.props.currentTeam.slug).url
        : '/',
);

/**
 * The rail, in the order a reader reaches for it.
 *
 * Dashboard stands alone. Explore holds what Bilis stores — logs, traces,
 * metrics — and Triage what it concludes from them: issues, the alerts that
 * are firing, and the fixes Autofix proposes. Setup is what a team configures, plus the docs. Traces and
 * Metrics each have two views, but those are tabs on the page (they carry the
 * filters across); a sub-menu in the rail would only repeat them.
 */
const homeNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
]);

const exploreNavItems = computed<NavItem[]>(() => [
    {
        title: 'Logs',
        href: logsUrl.value,
        icon: ScrollText,
    },
    {
        title: 'Traces',
        href: tracesUrl.value,
        icon: Waypoints,
    },
    {
        title: 'Metrics',
        href: metricsUrl.value,
        icon: ChartLine,
    },
]);

const issueBadge = computed(() => page.props.issueBadge ?? null);

const alertBadge = computed(() => page.props.alertBadge ?? null);

const triageNavItems = computed<NavItem[]>(() => [
    {
        title: 'Issues',
        href: issuesUrl.value,
        icon: Bug,
        badge: issueBadge.value
            ? {
                  count: issueBadge.value.unresolved,
                  alert: issueBadge.value.regressed > 0,
              }
            : null,
    },

    // What is firing now. The count is neutral like the issue count; its dot
    // is the one bit of data colour, since a firing series is the news.
    {
        title: 'Alerts',
        href: alertsUrl.value,
        icon: BellRing,
        badge: alertBadge.value
            ? {
                  count: alertBadge.value.firing,
                  alert: alertBadge.value.firing > 0,
                  alertLabel: 'firing',
              }
            : null,
    },

    // Autofix is off for a deployment that has no control plane configured,
    // and its endpoints 404 there — so the rail leaves it out entirely rather
    // than pointing at a page that cannot answer.
    ...(page.props.autofix?.enabled
        ? [
              {
                  title: 'Autofix',
                  href: autofixUrl.value,
                  icon: Wrench,
              },
          ]
        : []),
]);

const setupNavItems = computed<NavItem[]>(() => [
    {
        title: 'Projects',
        href: projectsUrl.value,
        icon: FolderKanban,
    },
    {
        title: 'Docs',
        href: docsIndex().url,
        icon: BookOpen,
        external: true,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <!--
          The brand stands alone up here, with the rail's own toggle beside it.
          The team lives at the foot with the account: both answer "who am I
          acting as", and a team name under the product name read as if the
          team were the title and Bilis its caption.
        -->
        <SidebarHeader class="pt-3 pb-2">
            <SidebarMenu>
                <SidebarMenuItem
                    class="flex items-center gap-1 group-data-[collapsible=icon]:flex-col"
                >
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="min-w-0 flex-1 hover:bg-sidebar-accent/60"
                    >
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                    <SidebarTrigger
                        class="hidden shrink-0 text-sidebar-foreground/80 md:inline-flex"
                    />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="flex flex-col gap-5 pt-2">
            <NavMain :items="homeNavItems" />
            <NavMain label="Explore" :items="exploreNavItems" />
            <NavMain label="Triage" :items="triageNavItems" />
            <NavMain label="Setup" :items="setupNavItems" />
        </SidebarContent>

        <SidebarFooter class="pt-2 pb-3">
            <NavAccount />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
