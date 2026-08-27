# Bilis Autofix — Laravel Monolith Specification

What the Bilis monolith (this repo) must implement to act as the **control plane** for the auto-fixer. The execution side is **Ayos**, a standalone single-purpose service in its own repo (see `specs/ayos.md`); this app owns all credentials, all trust decisions, and the entire GitHub write path. Ayos is domain-agnostic — Bilis renders its error data into Ayos's generic `task` shape at dispatch time.

> Scope note: this is a new surface beyond the shipped v1 (which is logs-only). It should be built behind a per-project opt-in flag and must not touch any v1 invariant — ingest, LogQuery/R4, or the ClickHouse schema rules all stay as they are. Reads for fingerprinting go through new query methods that still follow R4.

## 1. Data model (SQLite migrations + models)

### `github_installations`
GitHub App installations, one per GitHub account/org, linked to a team.

- `id`, `team_id` FK, `installation_id` (GitHub's), `account_login`, `account_type`
- timestamps; unique on `installation_id`

The App's **private key (PEM)** is app-level config, not per-row: `AUTOFIX_GITHUB_APP_ID` + `AUTOFIX_GITHUB_PRIVATE_KEY` env vars (key base64-encoded in env; alternatively an `encrypted` cast column in a single settings row if it must be editable from the UI). Never exposed to Ayos.

### `project_repositories`
Maps a Bilis project to the repo the agent should fix.

- `project_id` FK, `github_installation_id` FK, `repo_full_name` ("org/app"), `default_branch`
- `autofix_enabled` bool (the opt-in), `test_cmd` nullable string, `max_concurrent` int, `daily_budget` int

### `fix_jobs`
One attempt to fix one fingerprint.

- `uuid` (route key + Ayos idempotency key), `project_id` FK, `project_repository_id` FK
- `fingerprint` string (indexed), `error_context` json (what was sent), `base_sha`
- `status`: `pending → dispatched → running → validating → pr_opened → merged | rejected | failed | cancelled | timeout`
- `diff` text nullable, `report` json nullable, `events` json nullable (persisted transcript)
- `pr_number`, `pr_url` nullable; `failure_reason` nullable
- timestamps incl. `dispatched_at`, `completed_at`

Fingerprint cooldown state derives from this table: latest job per fingerprint answers "is there an open PR / recent rejection?" — no separate `fix_attempts` table needed.

Factories for all three; team-scoped route binding for `{fixJob}` in `AppServiceProvider` like `{project}`.

## 2. Services (`app/Services/Autofix/`)

### `GitHubAppTokenService`
- `installationToken(GithubInstallation $i, string $repo, array $permissions): string`
- Builds the App JWT (RS256, 10 min), exchanges via `POST /app/installations/{id}/access_tokens` with `repositories: [$repo]` and explicit `permissions`.
- Two call sites, two scopes: **read** (`contents: read`) for job dispatch; **write** (`contents: write, pull_requests: write`) only inside `PullRequestPublisher`. Never request `workflows`.
- Cache read tokens ~50 min per (installation, repo). Uses the `Http` facade (no new package; `firebase/php-jwt` or hand-rolled RS256 via openssl for the JWT — prefer no new dependency if practical).

### `ErrorFingerprinter`
- `fingerprint(array $logRecord): string` — sha256 of (ServiceName, exception class, normalized top stack frames: strip line numbers, hashes, hex addresses, absolute path prefixes).
- Pure function, heavily unit-tested with real-world stack samples.

### `FixTriggerService`
Scheduled (`autofix:scan`, every ~5 min). For each project with autofix enabled:
1. Query ClickHouse for recent `SeverityNumber >= ERROR` rows (via a new `LogQuery` method that follows R4 — ProjectId + time-range predicate, parameterized).
2. Fingerprint, aggregate counts.
3. Trigger when: fingerprint unseen, OR regression (previous job `merged` and errors recur), AND above `min_count` threshold.
4. Enforce budgets: `max_concurrent` per project, `daily_budget`, per-fingerprint cooldown (skip if latest job is `pending…pr_opened` or `rejected` within N days).
5. Create `fix_jobs` row → dispatch `DispatchFixJob` queued job.

### `AyosClient`
HTTP client for Ayos (base URL + shared secret from `config/autofix.php`).
- `dispatch(FixJob $job): void` — builds the job spec (repo, `base_sha` resolved via GitHub API, read token, per-job LLM key/gateway token, constraints incl. `path_denylist`), signs body with HMAC (`X-Ayos-Signature` + `X-Ayos-Timestamp`), POSTs `/jobs`. A `429` from Ayos is backpressure, not failure: the job stays `pending` and is retried by the dispatcher.
- **`TaskRenderer`** (used by dispatch): converts the stored `error_context` into Ayos's generic `task` shape — the "fix this production error" framing goes into `task.instructions`; stack trace, sample log lines, and occurrence stats (delimited, untrusted) into `task.context`; the Bilis deep link into `task.links`. All error-domain vocabulary lives here, not in Ayos.
- `cancel(FixJob $job): void`.
- All requests signed; raise a domain exception on non-2xx other than 429 (job → `failed` with reason).

### `DiffValidator`
Runs on artifact receipt, **before** any GitHub write. Rejects (job → `rejected`, reason recorded) when:
- diff empty or does not apply cleanly to current `default_branch` head (repo moved → optionally one re-dispatch with fresh `base_sha`);
- any hunk touches `.github/**`, `.env*`, or configured denylist paths;
- diff exceeds `max_diff_lines`;
- Ayos report says tests failed (when `test_cmd` was set);
- binary files added.

### `PullRequestPublisher`
Only holder of write tokens.
- Applies the validated diff and pushes branch `autofix/{short-fingerprint}` using the Git Data API (create blobs → tree → commit → ref) — no local clone needed.
- Opens the PR as the bot: title from exception, body with error stats, the agent's summary, a deep link to the Bilis log view for that fingerprint, and a "review carefully — machine-generated" notice.
- Sets `fix_jobs.status = pr_opened`, stores `pr_number`/`pr_url`.

## 3. HTTP endpoints

### Internal API (Ayos → Laravel), `routes/api.php`
Follow `.ai/rules/routes.md` before touching this file.
- `POST /internal/autofix/artifacts` — HMAC-verified (new middleware `VerifyAyosSignature`, timestamp window ±5 min). Persists diff/report/events on the `fix_jobs` row, transitions status, dispatches `ValidateAndPublishFix` queued job. Idempotent per `job_id`.

### GitHub webhooks
- `POST /webhooks/github` — signature-verified (`X-Hub-Signature-256` with the App's webhook secret).
- Events: `installation` created/deleted (sync `github_installations`), `pull_request` closed (merged → job `merged`; unmerged → `rejected` + start cooldown).

### App routes (session auth, team policies), `routes/web.php`
- `GET  /autofix` — jobs index (Inertia).
- `GET  /autofix/{fixJob}` — job detail.
- `POST /autofix/{fixJob}/stream-token` — mints the Ed25519 stream JWT (claims: `sub`, `job` = uuid, `scope: stream:read`, `exp` +10 min); returns `{ token, stream_url }`. Policy: job's project belongs to current team.
- `POST /autofix/{fixJob}/cancel` — proxies to `AyosClient::cancel` (low-traffic RPC goes through Laravel; only the live stream goes direct).
- Project settings: CRUD for `project_repositories` (connect installation + repo, toggle autofix, test_cmd, budgets) — extend the existing project settings pages.

`FixJobPolicy` (`view`, `cancel`) mirroring existing team-scoped policies.

## 4. Verification loop (Bilis's unfair advantage)

Scheduled `autofix:verify` for jobs in `merged`:
- Query ClickHouse (R4-compliant) for the fingerprint's error rate since merge.
- Rate dropped to ~0 after a deploy window → comment on the PR ("error rate dropped X% since merge") and mark verified.
- Errors persist after N hours → comment that the fix did not take; clear the fingerprint cooldown so a re-attempt is allowed.

## 5. Frontend (Inertia/Vue)

- **Jobs index** (`resources/js/pages/autofix/`): table of fix jobs — fingerprint/exception, project, status badge, PR link, timestamps. Severity/status colouring must come from existing token families (severity + neutral ladder) — no new hues; chrome stays achromatic per the branding rules.
- **Job detail**: header (error context, deep link to logs, PR link) + **timeline** of session events. Running job: fetch stream token, connect SSE/WS directly to Ayos (`agents.` subdomain), render events live; reconnect with a fresh token on drop. Finished job: render the persisted `events` transcript from the prop — no Ayos round-trip.
- New reusable components (event timeline row, status badge if none fits) go into `/styleguide` in the same change, with Bilis-flavoured demo data.
- Sidebar: add "Autofix" to the Platform group in `AppSidebar.vue`, visible when any project has a repository connected (or always, with an empty/onboarding state mirroring `GetStartedPanel` patterns).
- Read `.ai/rules/js.md` and `.ai/rules/css.md` before frontend work; Wayfinder regeneration with `--with-form` after route changes.

## 6. Configuration

`config/autofix.php`:

```php
return [
    'enabled' => env('AUTOFIX_ENABLED', false),
    'ayos' => [
        'url' => env('AUTOFIX_AYOS_URL'),
        'stream_url' => env('AUTOFIX_AYOS_STREAM_URL'), // browser-facing, agents. subdomain
        'shared_secret' => env('AUTOFIX_SHARED_SECRET'),
    ],
    'github' => [
        'app_id' => env('AUTOFIX_GITHUB_APP_ID'),
        'private_key' => env('AUTOFIX_GITHUB_PRIVATE_KEY'),   // base64 PEM
        'webhook_secret' => env('AUTOFIX_GITHUB_WEBHOOK_SECRET'),
    ],
    'stream_jwt' => [
        'private_key' => env('AUTOFIX_STREAM_PRIVATE_KEY'),   // Ed25519; Ayos gets ONLY the public key
        'ttl_minutes' => 10,
    ],
    'llm' => [
        'api_key' => env('AUTOFIX_ANTHROPIC_API_KEY'),        // forwarded per job; prefer a gateway token if available
    ],
    'defaults' => [
        'timeout_s' => 900,
        'max_diff_lines' => 800,
        'min_error_count' => 5,
        'cooldown_days' => 7,
        'path_denylist' => ['.github/**', '.env*'],
    ],
];
```

CSP note: the Ayos stream host must be added to `connect-src` in `SecurityHeaders` (read `.ai/rules/middleware.md` first).

## 7. Testing (Pest)

- `GitHubAppTokenService`: JWT shape, token exchange + scoping, caching (Http::fake).
- `ErrorFingerprinter`: stability across line-number/path noise; dataset of real stacks.
- `FixTriggerService`: trigger conditions, budgets, cooldowns (fake ClickHouse responses via Http::fake).
- `VerifyAyosSignature` + webhook signature middleware: valid/invalid/stale timestamp.
- `DiffValidator`: every rejection rule, incl. `.github/` smuggling and apply-failure.
- `PullRequestPublisher`: Git Data API call sequence, PR body contents (Http::fake).
- Feature tests: artifact callback idempotency + status transitions; stream-token endpoint authorization (cross-team 403); cancel flow.
- No tests hit a real Ayos, GitHub, or ClickHouse.

## 8. Build order

1. **Foundations:** migrations/models/factories, `config/autofix.php`, `GitHubAppTokenService`, HMAC middleware. Testable with zero Ayos.
2. **Dispatch path:** `ErrorFingerprinter`, `FixTriggerService` (+ `autofix:scan`), `AyosClient`, artifact callback endpoint. Meets Ayos's milestone 1.
3. **Write path:** `DiffValidator`, `PullRequestPublisher`, GitHub webhooks, status lifecycle end-to-end.
4. **UI:** settings (connect repo), jobs index/detail with live stream, styleguide entries, sidebar.
5. **Loop:** `autofix:verify`, PR comments, cooldown clearing.

## Invariants (mirror of the architecture decisions)

- Ayos never receives the App private key, write tokens, or user/team identity — only per-job read tokens and job-scoped stream-JWT verification material.
- ProjectId/team scoping rules are identical to the rest of the app: fix jobs are reached only through team-scoped bindings; ClickHouse reads follow R4 and are parameterized.
- No GitHub write happens on an unvalidated diff; `.github/**` is always rejected; branch protection is assumed, never bypassed.
- All new ClickHouse queries go through `app/Services/Logs/` conventions — read `SCHEMA.md` and `.ai/rules/logs.md` first.
