---
paths:
  - 'app/Services/Ingest/**'
---

# Ingest

## Log ingest never returns 4xx for bad payloads
Ingest endpoints (routes/api.php: POST /api/v1/logs OTLP JSON, POST /api/v1/ingest simple JSON) are best-effort: malformed records are skipped and counted, never 400. Mappers return a MappedLogs value object (rows + rejected + errorMessage); controllers turn that into OTLP `partialSuccess` (200, `{}` on full success) or `{accepted, skipped}` (202). Any ClickHouseException — overload or not — becomes 503 with `Retry-After: 5`; never blame the client for storage failures.

DateTime64(9) values are built as strings by App\Services\Ingest\LogTimestamp (`Y-m-d H:i:s` + 9 fraction digits) so nanosecond precision survives; never round-trip nanos through a float or DateTime. ProjectId always comes from AuthenticateProjectApiKey::project($request), never the payload.

Protobuf OTLP is intentionally unsupported (would need a new dependency): content-type application/x-protobuf returns 415 with a JSON hint.
