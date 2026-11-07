import type { ComputedRef, InjectionKey } from 'vue';
import { computed, inject, provide } from 'vue';
import type { BreadcrumbItem } from '@/types';

/**
 * The layout's breadcrumbs, handed down to whatever page header is drawn.
 *
 * Pages declare their trail once, in their `layout` option, as they always
 * have. The app layout provides it here and {@link PageHeader} reads it, so the
 * trail is printed above the page title — the small uppercase line — instead
 * of in a bar of its own. One declaration, one place it shows up.
 */
const TRAIL_KEY: InjectionKey<ComputedRef<BreadcrumbItem[]>> =
    Symbol('page-trail');

export function providePageTrail(trail: () => BreadcrumbItem[]): void {
    provide(
        TRAIL_KEY,
        computed(() => trail()),
    );
}

export function usePageTrail(): ComputedRef<BreadcrumbItem[]> {
    return inject(
        TRAIL_KEY,
        computed(() => []),
    );
}
