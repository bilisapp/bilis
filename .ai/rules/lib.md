---
paths:
  - resources/js/lib/traces.ts
---

# Lib

## Span labels are derived from attributes, and the raw name stays reachable
OpenTelemetry says span names should be low-cardinality, so many exporters name a *type*, not an instance — 400 rows reading `claude_code.tool`, every kind `internal`. `spanLabel()` in `resources/js/lib/traces.ts` recovers the identity from attributes via a first-match-wins rules table; `spanDetail()` answers "what kind of work" and must stay low-cardinality, because that is what makes a column scannable.

Every rule keys on a published semantic-convention attribute (older spelling accepted alongside the current one). Do not add a rule that fires on a service name or a vendor's span name — a span earns a label by being well-described, not by coming from a sender we recognise.

The derived label is an interpretation and must never be the only name on screen: `useSpanNaming` (module-level, localStorage-backed, `smart` by default) drives a Smart/Raw toggle, the waterfall row carries the exporter's name in its `title`, and `SpanDetailPanel` prints it under the heading. Keep all three.
