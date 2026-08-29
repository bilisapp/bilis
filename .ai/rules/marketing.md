---
paths:
  - 'resources/js/marketing/**'
---

# Marketing

## Marketing JS is one Blade-only entry, never Inertia

Public pages load exactly one bundle: `resources/js/marketing/marketing.ts` (a Vite input), pushed via
`@push('scripts')` into the marketing layout's `@stack('scripts')`. It imports the page modules — `hero-shader` (Paper
ShaderMount), `live-tail`, `copy` — and each finds its own `data-*` hook and no-ops when absent, so a page pays only for
what it uses. Never import Inertia or anything from `resources/js/app.ts` here: keeping the marketing pages Inertia-free
is what lets SSR stay off.

Every module is a progressive enhancement over server-rendered markup: the live-tail pane renders its full stream in
Blade and JS only prepends to it; the copy button ships `hidden` and is revealed by JS. Do not render a control that
does nothing without JS.
