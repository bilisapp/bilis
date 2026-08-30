<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
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

/**
 * Which child a section is currently on, longest match first.
 *
 * A section's children share its prefix — `/acme/traces` and
 * `/acme/traces/latency` — so a plain prefix test lights both at once. The
 * longest matching href is the specific one, which leaves the parent-shaped
 * child ("All traces") lit for pages no sibling claims, such as a single
 * trace's waterfall at `/acme/traces/{id}`.
 */
const activeChild = (item: NavItem): NavItem | undefined =>
    (item.items ?? [])
        .filter((child) => isCurrentOrParentUrl(child.href))
        .sort((a, b) => toUrl(b.href).length - toUrl(a.href).length)[0];

/**
 * Sections the reader has opened or closed by hand, by title.
 *
 * Until one is touched, a section follows the page: the section you are in is
 * open, the others are shut. Once someone has an opinion about a section it is
 * kept for the session — a rail that reopens what you just collapsed every time
 * you navigate is arguing with you.
 */
const toggled = ref<Record<string, boolean>>({});

const isOpen = (item: NavItem): boolean =>
    toggled.value[item.title] ?? isActive(item);

const setOpen = (item: NavItem, open: boolean) => {
    toggled.value = { ...toggled.value, [item.title]: open };
};
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel
            class="h-6 px-2 text-[11px] font-semibold tracking-[0.12em] text-sidebar-foreground/45 uppercase"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu>
            <!--
              Every item is wrapped in a Collapsible, whether or not it has
              children: the wrapper renders as the menu item itself (`as-child`),
              so a leaf costs no extra markup and a section does not need a
              second branch of this template to keep in step with the first.
            -->
            <Collapsible
                v-for="item in items"
                :key="item.title"
                as-child
                :open="isOpen(item)"
                @update:open="setOpen(item, $event)"
            >
                <SidebarMenuItem>
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

                    <!--
                      A section header is still a page, so the chevron is a
                      control of its own rather than something that swallows the
                      click: pressing "Traces" goes to the traces, pressing the
                      chevron shows what is under it. Both the chevron and the
                      sub-list are hidden when the rail is collapsed to icons,
                      where there is no room to say more than the icon does.
                    -->
                    <template v-if="item.items?.length">
                        <CollapsibleTrigger as-child>
                            <SidebarMenuAction
                                class="text-sidebar-foreground/60 transition-transform duration-200 peer-data-[active=true]/menu-button:text-sidebar-primary-foreground/80 hover:text-sidebar-foreground data-[state=open]:rotate-90"
                                :aria-label="`${isOpen(item) ? 'Hide' : 'Show'} ${item.title} pages`"
                                :data-test="`nav-toggle-${item.title.toLowerCase()}`"
                            >
                                <ChevronRight />
                            </SidebarMenuAction>
                        </CollapsibleTrigger>

                        <CollapsibleContent
                            class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down"
                        >
                            <SidebarMenuSub class="mt-1">
                                <SidebarMenuSubItem
                                    v-for="child in item.items"
                                    :key="child.title"
                                >
                                    <SidebarMenuSubButton
                                        as-child
                                        size="sm"
                                        :is-active="
                                            activeChild(item)?.title ===
                                            child.title
                                        "
                                    >
                                        <Link :href="child.href">
                                            <component
                                                :is="child.icon"
                                                v-if="child.icon"
                                            />
                                            <span>{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </template>
                </SidebarMenuItem>
            </Collapsible>
        </SidebarMenu>
    </SidebarGroup>
</template>
