---
paths:
  - 'app/Services/Logs/**'
---

# Logs

## Log queries are always scoped to server-resolved project ids
LogQuery never accepts a project slug. The controller resolves the current team's projects, filters them by the requested slug, and passes an explicit list<int> of ids; an empty list short-circuits without touching ClickHouse. Every user value (service, severity bounds, search term, time window, cursor) is bound as a ClickHouse `{name:Type}` parameter — nothing is interpolated. Search uses `hasToken(Body, ...)` for a single `[A-Za-z0-9_]{3,}` token (hits the Body token bloom filter) and an escaped `Body ILIKE '%…%'` otherwise. A ClickHouseException with isOverload() returns `unavailable: true` with zero rows so the page still renders; any other ClickHouse error is rethrown.

## Onboarding state comes from an existence query, never from the filter window
`LogOnboarding::state()` decides between `no-projects`, `no-logs` and `ready`. "Has this team ever logged?" is `LogQuery::hasAnyLogs()` — a `SELECT 1 ... LIMIT 1` over the team's full project id list, ignoring every filter, so an empty filtered window keeps the normal "no results" state instead of showing onboarding.

An overloaded ClickHouse answers true: a hiccup must never make an established team look brand new. Only the positive answer is cached (`logs.onboarding.received.{team}`, 6h); false is re-checked every request so the logs page flips over as soon as the first line lands.

The onboarding prop is eager, not deferred — pass it the team's *whole* project id list, not the slug-filtered subset the search uses.
