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
