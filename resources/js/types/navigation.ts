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
    /**
     * A live count beside the label — how much is waiting there.
     *
     * Only the count is achromatic; `alert` adds the one dot of severity
     * colour, reserved for news: an issue that came back after it was fixed,
     * an alert firing now. `alertLabel` says what the dot means to a screen
     * reader ("some regressed" when omitted).
     */
    badge?: { count: number; alert?: boolean; alertLabel?: string } | null;
    /** Leaves the app (the public docs): opens in a new tab, marked with ↗. */
    external?: boolean;
};
