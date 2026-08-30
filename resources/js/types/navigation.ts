import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /**
     * The pages under this one, shown as a sub-menu in the sidebar.
     *
     * The parent stays a real destination — the chevron opens the list, the
     * label still navigates — so a section is never a folder you have to open
     * before you can go anywhere. One level only: a rail that nests deeper is
     * a sitemap, not a way to get around.
     */
    items?: NavItem[];
};
