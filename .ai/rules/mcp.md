---
paths:
  - 'app/Mcp/**'
  - 'routes/ai.php'
  - 'app/Models/Passport/**'
  - 'resources/views/mcp/**'
---

# MCP

## The MCP server is read-only, and that is a promise made on a public page
`app/Mcp/Servers/BilisServer.php` exposes eight tools over logs and traces and
nothing that writes: no ingest, no delete, no project or key creation, no
Autofix. The consent screen, `/features/mcp` and `resources/docs/reference/mcp.md`
all state this to the person approving the connection, so adding a write tool is
not a code change — it is breaking a published guarantee. If one is ever wanted,
those three surfaces change in the same commit.

## Tools never build project ids themselves
Every tool uses `App\Mcp\Concerns\ResolvesScope`, which resolves the team from
`$request->user()` (never a route), maps a `project` slug to numeric ClickHouse
ids, and bounds the window. A slug must never reach SQL, and a team the user
does not belong to is refused with wording that does not reveal whether it
exists. Tools call `LogQuery`/`TraceQuery` — never ClickHouse directly, because
R4/R5/R10/R11/R13 live in those services.

`get-trace` goes through `App\Services\Autofix\TraceContextBuilder`, the same
builder the Autofix agent is handed: summary, spans inside the trace's own
bounds, and a capped text waterfall, with every empty outcome reported as a
state (`expired`/`missing`/`unavailable`) rather than thrown. Do not write a
second renderer.

## `routes/ai.php` is auto-loaded, and `/mcp` is the server's URI
`Laravel\Mcp\Server\McpServiceProvider` registers `routes/ai.php` itself — do
not add it to `bootstrap/app.php`. It loads *before* `routes/web.php`, and
`Mcp::web('/mcp', …)` claims GET, POST and DELETE on `/mcp`, so no web route may
use that path: the public page lives at `/features/mcp`.

## Every OAuth client is a third party
`App\Models\Passport\Client::skipsAuthorization()` returns `false`
unconditionally. Bilis has no first-party OAuth client, so approval is always a
deliberate click; `tests/Feature/Mcp/OAuthFlowTest.php` pins it. Tokens last a
day, refresh tokens 30 days.

Passport's signing keys must come from `PASSPORT_PRIVATE_KEY` /
`PASSPORT_PUBLIC_KEY` in production. `docker-entrypoint.sh` runs once per
container role, so keys written to disk would differ between web, horizon and
scheduler — and a regenerated pair disconnects every agent with nothing in the
logs to say why. Never add `passport:keys` to the entrypoint.

## Tests use the package's own harness
`BilisServer::actingAs($user)->tool(SomeTool::class, [...])` returns a
`Laravel\Mcp\Server\Testing\TestResponse` (`assertOk`, `assertSee`,
`assertHasErrors`). ClickHouse is faked the same way as everywhere else —
`Http::fake(['127.0.0.1:8123/*' => …])` — and the ProjectId invariant is proven
by asserting on the bound `param_projectIds`, plus `Http::assertNothingSent()`
for a project the team does not own. `usePassportKeys()` in `tests/Pest.php`
mints throwaway RSA keys so a test never touches the developer's own.
