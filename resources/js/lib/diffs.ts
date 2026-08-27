import type * as PierreDiffs from '@pierre/diffs';
import type { ResolvedAppearance } from '@/types';

/**
 * Everything Bilis needs from `@pierre/diffs`, in one place.
 *
 * The library is Shiki based and ships its own web component, so it is the
 * single heaviest dependency in the app — heavier than ECharts. Nothing here
 * imports it at module scope: `loadDiffs()` is a cached dynamic import, so only
 * a page that actually renders code pays for it.
 *
 * Theming follows the same rule as charts (see `lib/echarts.ts`): no colour is
 * written down here. The syntax palette is resolved from the app's CSS custom
 * properties at render time and re-resolved when the appearance flips, so a
 * diff is cut from the same material as the rest of the interface in both
 * modes. Syntax colour is spent on *code*, which is data — the same licence
 * the severity ramp and the chart series hold; the diff's own chrome (gutter,
 * context rows, header) stays achromatic.
 */

/** The library's module shape, inferred rather than duplicated. */
export type DiffsModule = typeof PierreDiffs;

let modulePromise: Promise<DiffsModule> | null = null;

/**
 * Load `@pierre/diffs` once per page load.
 */
export function loadDiffs(): Promise<DiffsModule> {
    modulePromise ??= import('@pierre/diffs');

    return modulePromise;
}

/**
 * The resolved CSS custom properties a rendered diff needs.
 */
export type DiffTokens = {
    foreground: string;
    mutedForeground: string;
    background: string;
    /** The five chart hues, which double as the syntax palette. */
    palette: string[];
    /** `--severity-debug`: the teal an added line is tinted with. */
    addition: string;
    /** `--severity-error`: the crimson a removed line is tinted with. */
    deletion: string;
    monoFontFamily: string;
    sansFontFamily: string;
};

const FALLBACK_MONO =
    '"Geist Mono", ui-monospace, SFMono-Regular, Menlo, monospace';
const FALLBACK_SANS = 'Geist, ui-sans-serif, system-ui, sans-serif';

/**
 * The neutral tokens used when there is no document to read them from.
 */
function fallbackTokens(): DiffTokens {
    return {
        foreground: 'currentColor',
        mutedForeground: 'currentColor',
        background: 'transparent',
        palette: [],
        addition: 'currentColor',
        deletion: 'currentColor',
        monoFontFamily: FALLBACK_MONO,
        sansFontFamily: FALLBACK_SANS,
    };
}

/**
 * Read the diff-relevant CSS custom properties off the root element.
 *
 * The tokens are per-mode, so this has to be re-read whenever the appearance
 * flips — never cache the result across a theme change.
 */
export function readDiffTokens(): DiffTokens {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return fallbackTokens();
    }

    const styles = getComputedStyle(document.documentElement);
    const read = (name: string, fallback: string): string =>
        styles.getPropertyValue(name).trim() || fallback;

    return {
        foreground: read('--foreground', 'currentColor'),
        mutedForeground: read('--muted-foreground', 'currentColor'),
        background: read('--card', 'transparent'),
        palette: [1, 2, 3, 4, 5].map((index) =>
            read(`--chart-${index}`, 'currentColor'),
        ),
        addition: read('--severity-debug', 'currentColor'),
        deletion: read('--severity-error', 'currentColor'),
        monoFontFamily: read('--font-mono', FALLBACK_MONO),
        sansFontFamily: read('--font-sans', FALLBACK_SANS),
    };
}

/**
 * Map the app's tokens onto Shiki's css-variables theme scopes.
 *
 * Shiki resolves a handful of broad scopes rather than a full TextMate theme,
 * which is exactly the right grain here: five hues, deliberately assigned, so
 * a diff never introduces a colour the styleguide cannot account for.
 */
function themeVariables(tokens: DiffTokens): Record<string, string> {
    const [gold, teal, navy, crimson, mauve] = tokens.palette;
    const hue = (value: string | undefined): string =>
        value || tokens.foreground;

    return {
        foreground: tokens.foreground,
        background: tokens.background,
        'token-comment': tokens.mutedForeground,
        'token-keyword': hue(crimson),
        'token-string': hue(teal),
        'token-constant': hue(gold),
        'token-function': hue(navy),
        'token-parameter': hue(mauve),
        'token-link': hue(navy),
    };
}

const themeRegistrations = new Map<string, Promise<void>>();

/**
 * Register — once — a Shiki theme built from the current tokens, and name it.
 *
 * The name carries a fingerprint of the values it was built from, so flipping
 * the appearance (or a future per-account palette) asks for a *different*
 * theme rather than trying to redefine one the highlighter has already
 * resolved and cached.
 */
export async function ensureDiffTheme(
    appearance: ResolvedAppearance,
    tokens: DiffTokens,
): Promise<string> {
    const variables = themeVariables(tokens);
    const name = `bilis-${appearance}-${fingerprint(JSON.stringify(variables))}`;

    // The registration promise is claimed synchronously: a page mounting many
    // canvases at once would otherwise all pass a has() guard during the await
    // and re-register the same theme, once per canvas.
    let registration = themeRegistrations.get(name);

    if (!registration) {
        registration = loadDiffs().then(
            ({ registerCustomCSSVariableTheme }) => {
                registerCustomCSSVariableTheme(name, variables);
            },
        );
        registration.catch(() => themeRegistrations.delete(name));
        themeRegistrations.set(name, registration);
    }

    await registration;

    return name;
}

/**
 * The custom properties that dress the diff's own chrome.
 *
 * Set on the element the component mounts into, so they inherit through the
 * shadow boundary. The library reads every one of these as a `var(x, …)`
 * fallback and never declares them itself, which is what lets an outside
 * value win.
 */
export function diffChromeVariables(
    tokens: DiffTokens,
): Record<string, string> {
    return {
        '--diffs-bg': tokens.background,
        '--diffs-light': tokens.foreground,
        '--diffs-dark': tokens.foreground,
        '--diffs-fg-number-override': tokens.mutedForeground,
        '--diffs-bg-context-override': tokens.background,
        '--diffs-addition-color-override': tokens.addition,
        '--diffs-deletion-color-override': tokens.deletion,
        '--diffs-font-family': tokens.monoFontFamily,
        '--diffs-header-font-family': tokens.sansFontFamily,
    };
}

/**
 * A short, stable fingerprint of a string — enough to name a theme by.
 */
function fingerprint(value: string): string {
    let hash = 2166136261;

    for (let index = 0; index < value.length; index++) {
        hash ^= value.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }

    return (hash >>> 0).toString(36);
}
