---
paths:
  - 'resources/views/marketing/**'
---

# Views Marketing

## Marketing copy is benefit-led; the vision is self-hosted observability with AI
Public copy leads with customer outcomes (find the line that explains the incident, your data never leaves the building, no per-GB bill), not implementation details — keep ClickHouse/Laravel/protobuf specifics off the homepage and minimal on /features. Positioning (since 2026-09-26): "observability your coding agent can read" — self-hosted logs, traces and metrics that people and MCP agents read alike; the hero leads with that and its secondary CTA goes to `/features/mcp`. MCP, metrics and Autofix are shipped, so never describe them as coming; alerting stays 'on the way' (never dated promises), keep eBPF/S3/replication/gRPC 'out on purpose', and always state self-hosting stays first-class even if a hosted tier lands. Keep the dry, precise, honest voice — the Honest limits section stays.

The hosted Free tier now exists and is published on `/pricing`: concrete limits read through `App\Services\Plans\PlanLimits` (never restated as literals in a view), all of them **soft** — shown and warned about, never enforced, nothing dropped or blocked. Paid tiers are "contact us" via `/contact?topic=upgrade`; self-serve billing and a checkout stay out of scope, so no page may present anything as purchasable. Self-hosting stays first-class and free, and has no plan at all — say so on every page that mentions the hosted service.
