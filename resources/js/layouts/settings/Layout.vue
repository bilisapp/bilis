<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Teams',
        href: teams(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="flex flex-1 flex-col">
        <!--
          One header for every settings page. Its eyebrow is the layout trail
          minus the page itself, so a team's page reads "Teams" above the
          title and links back to the list.
        -->
        <PageHeader title="Settings">
            Your profile, sign-in, teams and how Bilis looks.
        </PageHeader>

        <div
            class="flex flex-1 flex-col gap-8 border-t px-4 pt-8 pb-16 md:px-10 lg:flex-row lg:gap-12"
        >
            <!--
              The section nav reads like the rail: the current item is marked
              by a hairline ring rather than a fill, so it stays legible on the
              white surface without spending a colour.
            -->
            <aside class="w-full shrink-0 lg:w-48">
                <nav
                    class="flex gap-1 overflow-x-auto lg:flex-col"
                    aria-label="Settings"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href) ? 'page' : undefined
                        "
                        :class="
                            cn(
                                'flex h-8 shrink-0 items-center gap-2 rounded-md px-2.5 text-sm whitespace-nowrap transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                isCurrentOrParentUrl(item.href)
                                    ? 'font-medium text-foreground ring-1 ring-border'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )
                        "
                    >
                        <component
                            :is="item.icon"
                            v-if="item.icon"
                            class="size-4"
                        />
                        {{ item.title }}
                    </Link>
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <section class="flex max-w-2xl flex-col gap-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
