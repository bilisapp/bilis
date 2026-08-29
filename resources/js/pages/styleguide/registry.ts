import type { Component } from 'vue';
import type { StyleguideDemo } from './context';
import AppComponentsSection from './partials/AppComponentsSection.vue';
import AutofixSection from './partials/AutofixSection.vue';
import ChartsSection from './partials/ChartsSection.vue';
import ComponentsBasics from './partials/ComponentsBasics.vue';
import ComponentsOverlays from './partials/ComponentsOverlays.vue';
import PaletteSection from './partials/PaletteSection.vue';
import SeveritySection from './partials/SeveritySection.vue';
import TokensSection from './partials/TokensSection.vue';
import TypographySection from './partials/TypographySection.vue';

/**
 * One showcase entry: a titled section of the gallery with its own anchor.
 *
 * The entry owns nothing but its metadata and the component that draws its
 * demos — the heading, the anchor, the nav row and the search index are all
 * derived from this record, so a new entry is one object in one array.
 */
export type StyleguideEntry = {
    /** Anchor and deep link target. Also namespaces the demo anchors inside. */
    id: string;
    /** Nav label and the first thing the filter matches on. */
    name: string;
    /** Rendered under the heading, and searched alongside the name. */
    description: string;
    /** The component that renders the entry's demos. */
    component: Component;
    /** Optional wrapper classes when the demos want a grid rather than a stack. */
    bodyClass?: string;
};

export type StyleguideCategory = {
    id: string;
    title: string;
    summary: string;
    entries: StyleguideEntry[];
};

/**
 * The single source of truth for the styleguide.
 *
 * Both the nav and the page body are rendered from this list, so the two
 * cannot drift: adding a component to the showcase means adding a `DemoBlock`
 * inside an existing entry's component (it registers its own anchor and turns
 * up in the nav and the filter by itself), or adding one entry object here.
 */
export const STYLEGUIDE_CATEGORIES: StyleguideCategory[] = [
    {
        id: 'foundations',
        title: 'Foundations',
        summary:
            'The material everything else is cut from: the achromatic ladder, the semantic tokens on top of it, the one place colour is spent, and the type scale.',
        entries: [
            {
                id: 'surfaces',
                name: 'Neutral ladder',
                description:
                    "Bilis spends colour on log severity and nowhere else. Everything that is not a severity — every surface, border, button, focus ring and piece of type — is cut from this achromatic ladder. The steps all carry the same faint cool cast so they read as one material rather than as a pile of unrelated greys, and the discipline is what leaves the data — the severity ramp and the chart series, both drawn from the mark's tail — as the only hues a reader can find on the screen. Ordered here from the deepest rail to full ink; every step inverts with the mode.",
                component: PaletteSection,
            },
            {
                id: 'tokens',
                name: 'Semantic tokens',
                description:
                    'Every swatch below is painted with its own utility, so this grid is also the proof that the theme inverts correctly. Toggle the appearance switch at the top of the page and watch this section swap.',
                component: TokensSection,
            },
            {
                id: 'severity',
                name: 'Severity scale',
                description:
                    'Six buckets, mapped from the OpenTelemetry severity number. The dot and text utilities live in app.css and are exposed through SEVERITY_DOT_CLASS and SEVERITY_TEXT_CLASS in resources/js/lib/logs.ts — always import those maps rather than writing the class strings by hand, because the values differ between light and dark mode.',
                component: SeveritySection,
            },
            {
                id: 'typography',
                name: 'Typography',
                description:
                    'Geist for everything the interface says, Geist Mono for everything a machine wrote. The split is the whole type system: if it came out of a log — a body, a timestamp, an id, an attribute pair — it is monospace; if the interface is talking about that data, it is Geist. Headings stay tight; body copy sits at 14px and support copy at 12px, so a dense log table and its surrounding chrome share one rhythm.',
                component: TypographySection,
            },
        ],
    },
    {
        id: 'components',
        title: 'Components',
        summary:
            'Every family in resources/js/components/ui, rendered with the props and variants its index.ts actually exports. Nothing here is styled locally: if a demo looks wrong in one of the two modes, the token behind it is wrong.',
        entries: [
            {
                id: 'components-basics',
                name: 'Basics',
                description:
                    'The primitives a form or a panel is assembled from — buttons, badges, fields and the placeholders that stand in while data loads.',
                component: ComponentsBasics,
                bodyClass: 'grid items-start gap-4 lg:grid-cols-2',
            },
            {
                id: 'components-overlays',
                name: 'Overlays and menus',
                description:
                    'Everything that opens on top of the page or expands in place: dialogs, menus, sheets, tooltips and toasts.',
                component: ComponentsOverlays,
                bodyClass: 'grid items-start gap-4 lg:grid-cols-2',
            },
            {
                id: 'charts',
                name: 'Charts',
                description:
                    "Charts are Apache ECharts, always wrapped in ChartCanvas. Chart series are one of only two places this product spends colour — the interface itself is achromatic, and a series is data. Both data palettes come from the same place: the three stripes in the Bilis mark's tail, plus its navy body. The five series are those colours in order, authored twice so they hold on both the near-white and the dark card. A severity chart ignores this palette entirely and reads the --severity-* tokens directly, so its bars always agree with the dots in the log viewer.",
                component: ChartsSection,
            },
        ],
    },
    {
        id: 'product',
        title: 'Product surfaces',
        summary:
            'The Bilis-specific pieces built on top of the primitives, rendered with demo data so these blocks double as a regression check.',
        entries: [
            {
                id: 'app-components',
                name: 'App components',
                description:
                    'The Bilis-specific pieces that sit on top of the primitives. Everything below is the real component with demo data, so these blocks double as a regression check when the log UI changes.',
                component: AppComponentsSection,
            },
            {
                id: 'autofix',
                name: 'Autofix',
                description:
                    'The surfaces that show what an agent did — to a production error the scan found, or to a change somebody asked for: a status ladder cut from the severity ramp, a session transcript, and the one component in the app that renders code.',
                component: AutofixSection,
            },
        ],
    },
];

/** Every entry, flattened, in page order. */
export const STYLEGUIDE_ENTRIES: StyleguideEntry[] =
    STYLEGUIDE_CATEGORIES.flatMap((category) => category.entries);

export type { StyleguideDemo, StyleguideDemoRegistry } from './context';
export { demoRegistryKey, entryIdKey, slugify } from './context';

/** One row of the nav: an entry plus whichever of its demos should be listed. */
export type NavEntry = {
    entry: StyleguideEntry;
    demos: StyleguideDemo[];
};

/** A category as the nav renders it, with its non-matching entries already dropped. */
export type NavGroup = {
    id: string;
    title: string;
    entries: NavEntry[];
};
