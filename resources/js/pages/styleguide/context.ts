import type { InjectionKey } from 'vue';

/** One demo inside an entry, registered by `DemoBlock` as it mounts. */
export type StyleguideDemo = {
    id: string;
    title: string;
};

export type StyleguideDemoRegistry = {
    register: (entryId: string, demo: StyleguideDemo) => void;
};

/**
 * Demos announce themselves rather than being listed twice.
 *
 * `DemoBlock` injects the registry and reports its anchor on mount, which is
 * what lets the nav and the filter cover individual components without a
 * parallel list that can fall out of step with the markup.
 */
export const demoRegistryKey: InjectionKey<StyleguideDemoRegistry> = Symbol(
    'styleguide-demo-registry',
);

/** The id of the entry a `DemoBlock` is rendered inside, provided by `SectionShell`. */
export const entryIdKey: InjectionKey<string> = Symbol('styleguide-entry-id');

/** Turn a demo title into an anchor-safe slug. */
export function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
