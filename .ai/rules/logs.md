---
paths:
  - 'app/Services/Logs/**'
---

# Logs

## Log queries are always scoped to server-resolved project ids
LogQuery never accepts a project slug. The controller resolves the current team's projects, filters them by the requested slug, and passes an explicit list<int> of ids; an empty list short-circuits without touching ClickHouse. Every user value (service, severity bounds, search term, time window, cursor) is bound as a ClickHouse `{name:Type}` parameter — nothing is interpolated. Search uses `hasToken(Body, ...)` for a single `[A-Za-z0-9_]{3,}` token (hits the Body token bloom filter) and an escaped `Body ILIKE '%…%'` otherwise. A ClickHouseException with isOverload() returns `unavailable: true` with zero rows so the page still renders; any other ClickHouse error is rethrown.
