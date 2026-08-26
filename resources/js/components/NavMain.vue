<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

/**
 * A nested page belongs to its section: `/acme/projects/checkout` keeps
 * Projects lit. The root href is matched exactly, because every path starts
 * with a slash and would otherwise light up the whole menu.
 */
const isActive = (item: NavItem): boolean =>
    toUrl(item.href) === '/'
        ? isCurrentUrl(item.href)
        : isCurrentOrParentUrl(item.href);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel
            class="h-6 px-2 text-[11px] font-semibold tracking-[0.12em] text-sidebar-foreground/45 uppercase"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <!--
                  The active item takes the work surface as its fill, so the
                  current page is legible from the rail's silhouette. Hover
                  stays a quiet lift, which keeps the two states distinct.
                -->
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                    class="data-[active=true]:bg-sidebar-primary data-[active=true]:font-semibold data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
