---
paths:
  - 'resources/views/marketing/tools/**'
---

# Tools

## Free tools are server-computed GET forms
Each /tools page (ToolsController, services in app/Services/Tools) computes its result in PHP from the query string so it works without JS and every result is a link; `resources/js/marketing/tool-form.ts` only re-fetches the page and swaps `[data-tool-result]` — never duplicate the arithmetic in TS. Bad input is clamped or explained, never a 422. Wrap pages in `<x-tools.page>` and register new tools in `ToolCatalog` (feeds /tools, the "more tools" strip and the sitemap). The log-levels OTel column comes from `Ingest\LogSeverity::numberForText()`, never typed in. The cost calculator's defaults are Datadog list prices checked 2026-10-09 — re-check before editing the copy.
