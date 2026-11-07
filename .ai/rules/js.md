---
paths:
  - 'resources/js/**'
---

# Js

## Charts: always go through ChartCanvas + lib/echarts.ts
Charts use Apache ECharts, wrapped in `@/components/ChartCanvas.vue` (props: `option`, `height`, `notMerge`). It handles ResizeObserver autoresize, dispose on unmount, and a full re-init when the appearance flips.

Never `import ... from 'echarts'` — that pulls the whole library (~1.1MB). Register renderers/charts/components once in `resources/js/lib/echarts.ts` via `use([...])` and add the matching option type to `BilisChartOption`.

Never hardcode chart colours. The ECharts theme is built from the CSS custom properties (`--chart-1..5`, `--foreground`, `--muted-foreground`, `--border`, `--card`) read off the root element; for per-series colours use `useChartTokens()` (`@/composables/useChartTokens`), whose `tokens.severity` map matches the `--severity-*` tokens used by the log viewer.

## Inertia is app-only; SSR is off
Inertia renders authenticated, in-app surfaces. Public marketing pages are Blade (`resources/views/marketing/`, `<x-layouts.marketing>`) and never boot the Inertia bundle — that is what lets SSR stay disabled.

Inertia SSR is off in both places that matter: `config/inertia.php` (`ssr.enabled => false`) and `inertia({ ssr: false })` in `vite.config.ts`. There is no `ssr.ts` entry and no `build:ssr` script. Don't reintroduce them — put the page in Blade instead.

## Code and diff rendering uses @pierre/diffs
All code visualization in the app — diffs (autofix job diffs, PR previews) and any rendered source files — uses the @pierre/diffs library (diffs.com) via its **vanilla JS API** (FileDiff / CodeView / File; docs: https://diffs.com/docs#vanilla-js-api), wrapped in a reusable Vue component. Do not hand-roll diff HTML and do not use react-diff-view/diff2html/etc. It is Shiki-based: theme it from the app's CSS tokens / dark-light modes like ChartCanvas does for ECharts — no hardcoded highlight themes that ignore the mode.

## A hoverable row never puts its text on the hover edge
Anything that fills on hover (a row, a list link, a KPI tile) has at least 12px of horizontal padding of its own. Full-bleed lists get it from the gutter (`px-4 md:px-10` on the row). A row inside a guttered column, which has to stay aligned with its SectionHeader, spills into the gutter instead: `-mx-3 px-3 rounded-md` (a KPI tile that starts a row: `-ml-3 pl-3`). Without this the hover band starts exactly at the first character and the numbers touch its right edge.
