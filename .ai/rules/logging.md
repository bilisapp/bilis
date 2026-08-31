---
paths:
  - 'app/Logging/**'
---

# Logging

## The Bilis shipper must never break the app that logs
BilisHandler buffers mapped records and sends ONE POST (a JSON array) to the simple ingest endpoint. Every transport call is wrapped: timeouts, connection errors, non-2xx and encoding failures are swallowed and the batch is DROPPED, never retried — a failing sink must not stall requests. Never report a failure through the Log facade: this handler is usually inside the stack that would receive that line, so `error_log` is the ceiling.

Flush happens on a full buffer, on `close()` (Monolog's shutdown), on `__destruct`, and on the `terminating()` hook the factory registers (so the POST lands after the response under FPM/Octane). The buffer is cleared before sending and an empty flush makes zero HTTP calls — that is the whole double-flush guard, and it is what keeps the handler reusable across Octane requests. Do not add one-shot "already flushed" state.

Wire shape must stay what SimpleLogMapper accepts: `message`, `level` = strtolower(Monolog level name) (already a
LogSeverity alias), `timestamp` = RFC3339 with microseconds (`Y-m-d\TH:i:s.uP`), `service`, optional top-level
`trace_id` / `span_id`, `context` = context plus `extra` merged under an `extra.` prefix. BilisLogger returns a
NullHandler logger when endpoint or api_key is blank, so the `bilis` channel is safe in any stack. Keep the onboarding
snippet in resources/js/components/GetStartedPanel.vue in sync with this mapping (it inlines the handler source at build
time via `?raw`, so a source change needs a rebuild, not an edit).

## Trace ids must be top-level fields, not context keys

SimpleLogMapper reads `trace_id`/`traceId` and `span_id`/`spanId` only at the **top level** of a record; anything inside
`context` becomes a `LogAttributes` entry. So `Log::info('x', ['trace_id' => …])` shipped as-is lands as an attribute,
the `TraceId` column stays empty, and the log↔span link silently never fires. `BilisHandler::map()` therefore lifts
those four keys out of `context` and out of `extra` (where a Monolog processor puts them) onto top-level `trace_id`/
`span_id`, and removes them from the shipped context so the id is stored once. Context beats extra; both spellings are
always stripped. A hex value is lowercased (the trace tables store lowercase hex and the join is a string compare); a
non-hex string is passed through; a non-string is left in context; an empty string is dropped. The documented processor
for the OTel PHP SDK (`resources/docs/ingestion/shippers.md`, "Correlating logs with traces") writes into `extra` and
guards on `SpanContext::isValid()` — an invalid context has all-zero ids, which would never join. Tests: "promoted to
top-level fields" and siblings in tests/Feature/Logging/BilisLoggingTest.php.

## BILIS_ENDPOINT stores the Bilis origin
BILIS_ENDPOINT is configured as the Bilis base origin only, e.g. https://bilis.app. The log shipper resolves the simple JSON route internally as /api/v1/ingest so future routes can share the same origin setting. Existing full /api/v1/ingest values may be accepted for compatibility, but examples should show origin-only.
