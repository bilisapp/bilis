---
paths:
  - 'app/Services/Autofix/**'
---

# Autofix

## Stream tokens and the GitHub install state are the two browser-facing credentials
`StreamTokenIssuer` mints the only credential Bilis hands a browser: an Ed25519 JWT (`sub`/`job`/`scope: stream:read`/`exp`), signed with libsodium — no JWT package, same instinct as the hand-rolled RS256 in `GitHubAppTokenService`. It accepts a 64-byte secret key or a 32-byte seed. Ayos enforces `exp` at connect time only, so the client reconnects with a fresh token rather than refreshing one; never bake the token into a prop.

`GitHubInstallState` carries the team through GitHub's Setup URL round trip, which has one fixed absolute URL for the whole App and so no team in the path. It is encrypted (authenticated, so tampering fails to decrypt), expires in 10 minutes, and is single-use via an atomic `Cache::add` on its nonce. The `installation` webhook stays the source of truth; the setup callback is UX sugar.

`GitHubInstallationClient` mints an installation-wide, metadata-only token for `/installation/repositories` — deliberately not `GitHubAppTokenService::installationToken()`, which always pins `repositories: [one]`. Never cache or reuse it.

## Fix job budgets live in FixJobBudget, and the two job types share one pool
`FixJobBudget` is the single implementation of the per-repository `max_concurrent` / `daily_budget` checks. `FixTriggerService` injects it and `AutofixController::store` calls `refusalReason()` for the inline validation error — never re-derive the arithmetic in a second place.

Both budgets count every `fix_jobs` row for the repository regardless of `type`: an error the scan raised and a custom job a teammate typed draw from the same pool, because what is rationed is agent runs against one codebase.

`type = custom` jobs have null `fingerprint` and null `error_context`. They are excluded explicitly (`where('type', FixJobType::Error)`) from fingerprint cooldowns in `FixTriggerService` and from `FixVerificationService` — do not rely on SQL's treatment of NULL comparison for that. Every reader of `fingerprint`/`error_context` must be null-safe.
