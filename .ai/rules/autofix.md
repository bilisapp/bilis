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

## Model credentials are per team, many per team, and pinned per job
A team holds N model API keys (`team_llm_credentials`), one row per key: provider (`LlmProvider`: anthropic/openai/openrouter), label, encrypted `api_key`, last-4 `hint`, and exactly one `is_default` — enforced in `TeamLlmCredential::makeDefault()`, not by a partial unique index.

Keys are never fillable and never sent to the browser. They are written only through `TeamLlmCredential::add()`; the browser gets `toSummary()`.

`fix_jobs.team_llm_credential_id` is pinned when the job is RAISED (person's pick, or the scan taking the team default), not read at dispatch — so "which key paid for this" cannot move because someone edited team settings mid-run. It is `nullOnDelete`: a deleted credential leaves history intact and dispatch falls back to the team default via `LlmCredentials::forJob()`.

`LlmCredentials` resolves pinned → team default → `config('autofix.llm.*')` and returns a `ResolvedLlmCredential` (provider + key + host). The job spec sends `llm_provider`, `llm_key` and `llm_host` — Bilis holds the key so Bilis, never the runner, decides where it is valid. Ayos's `JobSpec` mirrors this and defaults `llm_provider` to anthropic; each provider there has its own wire API, host and model id (`PROVIDERS` in ayos `src/agent/pi.ts`), so adding a provider means changing both repos.

## A project holds many repositories; the service claim decides which fixes what
`project_repositories` was always a hasMany — the one-repository rule was product code (`Project::repository()`, now removed), not schema. A project ships several services that need not share a codebase.

`project_repository_services` maps `ServiceName` → repository, many-to-one, with `unique(project_id, service_name)` (project_id denormalised so the DB enforces "one service, one repository" per project). `*` is the catch-all: every service no sibling has named, at most one per project. The first repository connected gets it, so a one-repository project needs no configuration.

`FixTriggerService::scanRepository()` MUST scan per repository with `ProjectRepository::scanScope()` — `include` (named services) or `exclude` (the catch-all minus sibling claims), passed to `LogQuery::errorSamples()` as ServiceName predicates. Scanning the whole project per repository is the bug this prevents: every error raises a job on every repository, one of which is always the wrong codebase, both drawing budget.

A repository claiming nothing is SKIPPED, never falls back to the project — silence is the safe failure. `SaveProjectRepositoryRequest` refuses to enable autofix with no claim, and disconnecting hard-deletes claims (a soft-deleted row holding `checkout` would block re-claiming it invisibly).

Custom jobs name a `repository` id, not a project slug — the old `->first()` lookup silently picked an arbitrary repository.

## A log line raised by hand skips the scan thresholds, never the budgets
`FixTriggerService::raiseFromRow()` is the log-viewer path (POST `autofix/from-log`, `LogFixJobController`). It creates an ordinary `FixJobType::Error` job — same fingerprint, same `error_context` shape, `count: 1` and one sample — so the scan's cooldown applies to it afterwards.

`min_error_count` deliberately does NOT apply: it exists to stop an unattended loop spending on noise, and a person clicking "fix this" has made that call. `FixJobBudget::refusalReason()` still runs, and an *active* job on the same fingerprint short-circuits to that job instead of raising a duplicate.

The repository is never named by the browser: `ProjectRepository::forService($projectId, $serviceName)` resolves it from the service claims (named beats catch-all, autofix-enabled only). The project id in the body is matched against the team's own projects. The rest of the row is untrusted and only ever reaches the agent through `TaskRenderer`'s untrusted-data markers.

## A traced log line brings its waterfall, frozen at raise time and bounded twice

When the triggering row carries a `TraceId`, `FixTriggerService::errorContext()` asks `TraceContextBuilder` for the
trace and stores the result under `error_context['trace']`; a row without one stores no `trace` key at all, so an
untraced job's context is byte-identical to before. `TaskRenderer::trace()` echoes the stored text after the sample log
lines, inside the untrusted markers, under a `Trace:` heading — it never re-renders, because the job page (
`autofix/Show.vue`, read straight off `errorContext.trace`) must show exactly what the agent was handed.

It is fetched when the job is RAISED, not at dispatch, for the same reason the log row is: spans expire after 30 days
and pull requests are reviewed later than that. Project ids are `[(string) $repository->project_id]` — the scan's own
scope — never the row's. `TraceQuery::summary()` runs first (a point lookup on `trace_summary`'s key), and
`TraceQuery::spansBetween()` then reads exactly `[startedAt − 1 s, endedAt + 1 s]`, so a queue job or agent session
longer than five minutes reads whole (up to TraceQuery's window cap, reported as `truncated`). Only a summary without
usable times falls back to `spans()` bracketed around now.

Four states, four sentences, and none of them throws: `rendered`, `expired` (summary but no spans — designed, 90-day
summaries outlive 30-day spans), `missing` (no summary), `unavailable` (storage busy, or ANY `ClickHouseException` — the
trace is an enrichment and must never be why a fix job failed to be raised). An agent told "read the waterfall" and
handed nothing will go looking for it, so the empty states always say why in one line.

`TraceWaterfallRenderer` is pure and caps the text twice — `MAX_SPANS` (60) and `MAX_CHARS` (3,000) — because it is
appended to a prompt whose other parts are already bounded (`STACK_LIMIT`, `SAMPLE_LIMIT`) and a 2,000-span trace would
drown them. Over the span cap it keeps, in order: the triggering span (matched on the row's `SpanId`) and its ancestors,
its siblings, every `StatusCode = 'Error'` span, then the slowest; rendering order is always `SpanTree::flatten()`'s, so
the survivors still read as a waterfall. The character cap cuts whole lines only. `>>` marks the triggering span, `!!`
an Error span, and a trailing `(N more spans omitted)` says what was cut. Attributes are a curated allow-list (
`ATTRIBUTE_KEYS`: http/url, db, rpc, messaging, code.*, both old and stable semconv spellings, six per span), plus every
`exception` event as `type: message` — never the environment/identity/session keys the UI groups away. Mirror
`resources/js/lib/attributes.ts` when adding a key.
