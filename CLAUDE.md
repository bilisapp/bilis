# Bilis

Self-hostable log storage and search. **v1 scope is exactly this — nothing else**: one OTLP/HTTP ingest endpoint (+ simple JSON fallback), OTel-compatible ClickHouse logs table, and a log viewer UI (time range, project/service/severity filters, full-text search, live tail). Explicitly *not* in v1: traces, metrics, alerting, dashboards, saved searches, eBPF, S3 tiering, replication, billing. Push back on scope creep.

## Stack

- Laravel 13 (PHP 8.4) + Inertia v3 + Vue 3 + Tailwind v4 (shadcn-vue), Pest, SQLite app DB.
- **ClickHouse** stores the logs — accessed over its HTTP interface via `App\Services\ClickHouse\ClickHouseClient` (Laravel `Http` facade, **no ClickHouse composer package — keep it that way**). Config: `config/clickhouse.php` / `CLICKHOUSE_*` env vars.
- Deploy target: Traefik via Coolify on one OVH dedicated box, Octane/FrankenPHP.

## Architecture map

| Area | Where |
| --- | --- |
| ClickHouse client + exceptions | `app/Services/ClickHouse/` |
| ClickHouse DDL (idempotent, filename order) | `database/clickhouse/*.sql`, applied by `php artisan clickhouse:migrate` |
| Ingest endpoints (OTLP JSON + simple JSON) | `routes/api.php`, `app/Http/Controllers/Api/`, mappers in `app/Services/Ingest/` |
| API-key auth (key -> project) | `App\Http\Middleware\AuthenticateProjectApiKey`, alias `project.api-key`; keys are `bilis_`-prefixed, only sha256 hash stored (`App\Models\ProjectApiKey`) |
| Monolog shipper (in-app) | `app/Logging/BilisLogger.php` (custom-driver factory) + `BilisHandler.php`; buffered batch POST to the simple ingest endpoint, flushed on `terminating()`/`close()`/full buffer. Channel `bilis` in `config/logging.php`, off unless `LOG_STACK` names it; inert without `BILIS_ENDPOINT`/`BILIS_API_KEY` |
| Log querying for the UI | `app/Services/Logs/` (LogQuery, LogFilters, SeverityLevel) |
| Log viewer page | `LogsController`, `resources/js/pages/logs/`, `LogsToolbar.vue`, `LogEntryRow.vue`, `resources/js/lib/logs.ts` |
| Projects (team-scoped, slug route key) | `App\Models\Project`, belongs to existing Teams system |
| Projects & API keys UI | `ProjectController`, `ProjectApiKeyController`, `resources/js/pages/projects/`, project/API-key modals in `resources/js/components/`; `{project}` / `{apiKey}` route bindings are team-scoped in `AppServiceProvider` |
| Contextual onboarding (no projects -> no logs -> ready) | `App\Services\Logs\LogOnboarding` + `LogQuery::hasAnyLogs()`; `onboarding` prop from `LogsController` and `DashboardController`; `GetStartedPanel.vue` renders both steps on the logs page and the dashboard (until `ready`) |
| Left navigation | `AppSidebar.vue` (Platform: Dashboard, Logs, Projects; Resources: Styleguide), groups rendered by `NavMain.vue` (`label` prop); active state matches nested URLs via `isCurrentOrParentUrl` |
| Styleguide / component showcase | `/styleguide` route, `resources/js/pages/styleguide/` |
| Marketing pages (public, Blade only) | `resources/views/marketing/`, layout `resources/views/components/layouts/marketing.blade.php` |
| Public docs (Blade + CommonMark) | `resources/docs/{section}/{page}.md` (front matter: title/description/order, `_section.md` per group), `App\Services\Docs\` (DocsRepository/DocsRenderer/FrontMatter), `DocsController`, `resources/views/docs/`, layout `resources/views/components/layouts/docs.blade.php`, nav `resources/views/components/docs/nav.blade.php`, prose styles `.docs-prose` in `app.css`; routes `docs.index` / `docs.show` |
| Charts (Apache ECharts) | `ChartCanvas.vue` wrapper; register chart types in `resources/js/lib/echarts.ts`; theme comes from CSS tokens via `useChartTokens` — never hardcode chart colours |

## Invariants (do not break)

- **Ingest never returns 400.** Malformed records are skipped best-effort with counts (`partialSuccess` for OTLP). ClickHouse failure -> 503 + `Retry-After`. The client is never blamed.
- **ProjectId comes only from the authenticated API key** (ingest) or the current team's projects (UI). Never from the payload or from a slug reaching SQL. It is a `String` column, so ids are cast at the controller boundary. The sort key leading with it is *clustering, not isolation* — never call it a tenancy boundary.
- **`database/clickhouse/SCHEMA.md` is the source of truth** for the `otel_logs` table: pinned collector tag, exact DDL, rules R1–R9. Column names and types belong to the OTel exporter (R1); `ORDER BY`, `PARTITION BY`, `TTL`, indexes and `ProjectId` are ours. Read it before touching the DDL or any query against the table.
- **Every log query follows R4.** Sort key `(ProjectId, Timestamp, ServiceName)`: a plain `ProjectId IN … AND Timestamp >= {from} AND Timestamp <= {to}`, `ORDER BY Timestamp DESC`, no bucket expression. The base predicate is built in one method (`LogQuery::conditions()`); user filters append to it and never replace the ProjectId predicate.
- **Body search must match the index expression exactly** (R5, ClickHouse < 26.2): `hasToken(lower(Body), lower({q:String}))` against `INDEX idx_lower_body lower(Body) tokenbf_v1`. On a >= 26.2 floor both sides change together — text index, no `lower()`, `hasAnyTokens`.
- **All ClickHouse SQL is parameterized** with `{name:Type}` server-side placeholders — never string-interpolate values.
- Inserts use `async_insert=1` / `wait_for_async_insert=0` — success means *queued*, not durable.
- OTLP protobuf content-type -> 415 (JSON encoding only in v1; adding a protobuf dep requires approval).

## Frontend conventions

- **Public marketing pages are Blade, never Inertia.** Anything a logged-out visitor is meant to read (the `/` landing page and whatever follows it) lives in `resources/views/marketing/` under `<x-layouts.marketing>` and loads `@vite('resources/css/app.css')` only — no Inertia bundle. Inertia is for in-app, authenticated surfaces. Inertia SSR is off (`config/inertia.php`, `inertia({ ssr: false })`) and stays off: the pages that needed pre-rendering are Blade now.

- **Every new reusable Vue component must be added to the `/styleguide` showcase** (`resources/js/pages/styleguide/`) in the same change — add it to the matching section (or a new one) with realistic Bilis-flavored demo content. A component that isn't in the styleguide isn't done.
- **Charting: use Apache ECharts** (`echarts`) for all charts — not chart.js, not hand-rolled SVG. Not yet installed; add it (prefer tree-shakeable `echarts/core` imports) the first time a chart is built, wrap it in a reusable component themed via the CSS `--chart-1..5` / semantic tokens, and showcase it in the styleguide's Charts section.

## Git

- **Do not commit.** The user commits when they're ready — leave the working tree for them to review and stage. Only commit if they explicitly ask for one in the moment.

## Branding

**Colour belongs to data; the chrome is achromatic.** The whole interface — surfaces, borders, buttons, focus rings, nav, icons, type — is built from one neutral ladder (hue 225 at 8–20% saturation, per-mode) defined in `resources/css/app.css`. There is no accent colour, no brand hue in the UI, and no coloured primary button. Only two families carry hue, and both are data:

- **Severity** — `--severity-{trace,debug,info,warn,error,fatal}` and the `text-severity-*` / `bg-severity-*` utilities.
- **Chart series** — `--chart-1..5`.

Both are drawn from the Bilis mark's tail (`--color-mark-{gold,teal,crimson,navy}`), which is the palette's origin and is never used for chrome. `destructive` is the single stated exception, because it warns about an action rather than describing data.

Dark is the designed-for mode; light is authored separately, never derived. Font: **Geist** for the interface, **Geist Mono** for log data — self-hosted via the Vite font plugin. IBM Plex Mono is available as a per-account alternate (Settings -> Appearance). Wordmark: "Bilis" with the mark (`AppLogo.vue` / `AppLogoIcon.vue`), which keeps its tail colours. Living reference: the `/styleguide` page. Full system: `DESIGN.md`.

## Commands

```bash
composer run dev              # app + vite + queue + pail
php artisan test --compact    # Pest (use --filter/paths for speed)
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse    # larastan level per phpstan.neon — keep it clean
php artisan clickhouse:migrate
npm run build                 # must pass (vue-tsc + vite)
php artisan wayfinder:generate --with-form   # ALWAYS --with-form; without it .form() is stripped from every generated route and ~19 files break
```

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
