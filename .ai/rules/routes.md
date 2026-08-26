---
paths:
  - routes/api.php
---

# Routes

## Ingest throttling is keyed off the raw header, not the resolved key
`throttle:ingest` sorts ahead of the `project.api-key` alias in Laravel's middleware priority, so the limiter cannot read the resolved `ProjectApiKey`. It buckets on `sha256` of the raw key from the request instead — one bucket per credential, no database round trip per POST. Do not "fix" it to look the model up.

A rejection is a 429 with `Retry-After`, which exporters retry; that does not break the "ingest never returns 400" invariant. Limits live in `config/security.php`; 0 disables.
