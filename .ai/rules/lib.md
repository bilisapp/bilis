---
paths:
  - resources/js/lib/traces.ts
  - resources/js/lib/attributes.ts
---

# Lib

## Span labels are derived from attributes, and the raw name stays reachable
OpenTelemetry says span names should be low-cardinality, so many exporters name a *type*, not an instance — 400 rows reading `claude_code.tool`, every kind `internal`. `spanLabel()` in `resources/js/lib/traces.ts` recovers the identity from attributes via a first-match-wins rules table; `spanDetail()` answers "what kind of work" and must stay low-cardinality, because that is what makes a column scannable.

Every rule keys on a published semantic-convention attribute (older spelling accepted alongside the current one). Do not add a rule that fires on a service name or a vendor's span name — a span earns a label by being well-described, not by coming from a sender we recognise.

The derived label is an interpretation and must never be the only name on screen: `useSpanNaming` (module-level, localStorage-backed, `smart` by default) drives a Smart/Raw toggle, the waterfall row carries the exporter's name in its `title`, and `SpanDetailPanel` prints it under the heading. Keep all three.

## Span attributes are grouped and typed by key, never by value
Attributes arrive as a flat `Record<string, string>` — ClickHouse stores `Map(String, String)`, so every number, flag and stack trace comes back stringified. `groupAttributes()` sorts them into ordered groups (Outcome first, then the work, then the machinery, then Identity/Environment folded) and `describeAttribute()` assigns a kind.

The **key** decides the kind, not the value: `2` is a token count under `input_tokens` and a retry under `attempt`, and nothing about the string `2` separates them. Add new keys to `GROUPS`/`CODE_KEYS`, don't sniff values.

`formatValue()` only ever *reformats* — never truncates. Shortening is CSS, so selecting or copying a value still yields the stored value; `SpanAttribute.value` is what a copy must produce and `display` is only what is drawn.

Exactly one thing here gets colour: an attribute stating a failure (`isFailure()` — deliberately narrow), and it borrows `SPAN_STATUS_TEXT_CLASS.Error` rather than introducing a family. A panel that tints half its rows points at nothing.
