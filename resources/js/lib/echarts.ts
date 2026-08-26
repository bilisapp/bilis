import { BarChart, LineChart, PieChart } from 'echarts/charts';
import type {
    BarSeriesOption,
    LineSeriesOption,
    PieSeriesOption,
} from 'echarts/charts';
import {
    DatasetComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
    TransformComponent,
} from 'echarts/components';
import type {
    DatasetComponentOption,
    GridComponentOption,
    LegendComponentOption,
    TooltipComponentOption,
} from 'echarts/components';
import { registerTheme, use } from 'echarts/core';
import type { ComposeOption } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { SEVERITY_CSS_VARIABLE, SEVERITY_LEVELS } from '@/lib/logs';
import type { ResolvedAppearance, SeverityLevel } from '@/types';

/**
 * Every ECharts feature Bilis ships. Adding a new chart type is a one-line
 * change here (plus its option type in `BilisChartOption` below) — never
 * import from `echarts` directly in a component, that pulls in the whole
 * library and undoes the tree shaking.
 */
use([
    CanvasRenderer,
    BarChart,
    LineChart,
    PieChart,
    DatasetComponent,
    GridComponent,
    LegendComponent,
    TooltipComponent,
    TransformComponent,
]);

/**
 * The option type for everything registered above.
 */
export type BilisChartOption = ComposeOption<
    | BarSeriesOption
    | LineSeriesOption
    | PieSeriesOption
    | DatasetComponentOption
    | GridComponentOption
    | LegendComponentOption
    | TooltipComponentOption
>;

/**
 * The registered ECharts theme names, one per appearance mode.
 */
export const CHART_THEMES: Record<ResolvedAppearance, string> = {
    light: 'bilis-light',
    dark: 'bilis-dark',
};

/**
 * The resolved values of the CSS custom properties a chart may need.
 */
export type ChartTokens = {
    /** `--chart-1` … `--chart-5`, in order. */
    palette: string[];
    /** `--severity-*`, keyed by bucket, matching the log viewer. */
    severity: Record<SeverityLevel, string>;
    foreground: string;
    mutedForeground: string;
    border: string;
    card: string;
    cardForeground: string;
    background: string;
    fontFamily: string;
};

const FALLBACK_FONT_FAMILY =
    "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

/**
 * Whether the document is currently rendered in dark mode.
 *
 * `updateTheme()` in `useAppearance` toggles this class on the root element,
 * so it is the single source of truth for both `system` and explicit modes.
 */
export function resolvedAppearanceFromDocument(): ResolvedAppearance {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}

/**
 * Read the chart-relevant CSS custom properties off the root element.
 *
 * The tokens are per-mode, so this has to be re-read whenever the appearance
 * flips — never cache the result across a theme change.
 */
export function readChartTokens(): ChartTokens {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return {
            palette: [],
            severity: Object.fromEntries(
                SEVERITY_LEVELS.map((level) => [level, 'currentColor']),
            ) as Record<SeverityLevel, string>,
            foreground: 'currentColor',
            mutedForeground: 'currentColor',
            border: 'currentColor',
            card: 'transparent',
            cardForeground: 'currentColor',
            background: 'transparent',
            fontFamily: FALLBACK_FONT_FAMILY,
        };
    }

    const styles = getComputedStyle(document.documentElement);
    const token = (name: string, fallback = 'currentColor'): string =>
        styles.getPropertyValue(name).trim() || fallback;

    return {
        palette: [1, 2, 3, 4, 5].map((index) => token(`--chart-${index}`)),
        severity: Object.fromEntries(
            SEVERITY_LEVELS.map((level) => [
                level,
                token(SEVERITY_CSS_VARIABLE[level]),
            ]),
        ) as Record<SeverityLevel, string>,
        foreground: token('--foreground'),
        mutedForeground: token('--muted-foreground'),
        border: token('--border'),
        card: token('--card', 'transparent'),
        cardForeground: token('--card-foreground'),
        background: token('--background', 'transparent'),
        fontFamily: token('--font-sans', FALLBACK_FONT_FAMILY),
    };
}

/**
 * Build an ECharts theme out of the resolved design tokens.
 */
function buildChartTheme(tokens: ChartTokens): Record<string, unknown> {
    const axis = {
        axisLine: { lineStyle: { color: tokens.border } },
        axisTick: { lineStyle: { color: tokens.border } },
        axisLabel: { color: tokens.mutedForeground },
        splitLine: { lineStyle: { color: tokens.border, opacity: 0.6 } },
        splitArea: { show: false },
    };

    return {
        color: tokens.palette,
        backgroundColor: 'transparent',
        textStyle: {
            fontFamily: tokens.fontFamily,
            color: tokens.foreground,
        },
        title: {
            textStyle: { color: tokens.foreground },
            subtextStyle: { color: tokens.mutedForeground },
        },
        legend: {
            textStyle: { color: tokens.mutedForeground },
            inactiveColor: tokens.border,
        },
        tooltip: {
            backgroundColor: tokens.card,
            borderColor: tokens.border,
            textStyle: { color: tokens.cardForeground },
            axisPointer: {
                lineStyle: { color: tokens.border },
                crossStyle: { color: tokens.border },
                label: {
                    backgroundColor: tokens.card,
                    color: tokens.cardForeground,
                    borderColor: tokens.border,
                },
            },
        },
        grid: { borderColor: tokens.border },
        categoryAxis: axis,
        valueAxis: axis,
        timeAxis: axis,
        logAxis: axis,
    };
}

/**
 * Re-register both themes from the currently resolved tokens and return the
 * theme name to initialise with. Registration is cheap and idempotent, and
 * re-running it is what makes the colours flip with the rest of the UI.
 */
export function registerChartTheme(
    appearance: ResolvedAppearance,
    tokens: ChartTokens,
): string {
    const name = CHART_THEMES[appearance];

    registerTheme(name, buildChartTheme(tokens));

    return name;
}
