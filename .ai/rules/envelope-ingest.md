---
paths:
  - 'app/Services/Ingest/Envelope/**'
---

# Envelope Ingest

## The wire protocol is a vendor's; the code's names are not
Bilis accepts the envelope format the Sentry SDKs speak, so applications that already report exceptions through one can ship them here as logs. That is the only place the vendor is named: the docs (`resources/docs/ingestion/sentry.md`) and two protocol literals (`AuthenticatePublicKey::AUTH_HEADER`, `::KEY_PARAMETER`). Everything in code is named for the mechanism — `Envelope`, `EnvelopeItem`, `ErrorEventMapper`, `EnvelopeIngestController`, `project.public-key` — so the same path can serve another client that authenticates by URL. Keep it that way; do not reintroduce vendor names in classes, routes, props or attribute keys.

## Paths and auth belong to the client, the project does not
A client builds its URL from its DSN, so the routes live at `POST /api/{dsnProjectId}/envelope` and `/store` (pinned to digits so they cannot shadow `/api/v1/*`). The path's project id is the DSN's and is ignored — the project is the one the public key belongs to (SCHEMA.md R2).

Auth is `project.public-key` (`AuthenticatePublicKey`), which reads the key from the auth header, `Authorization`, or the query string and looks up `project_api_keys.public_key` in plaintext (nothing to protect that the DSN does not disclose). It sets the same request attributes the secret-key middleware does, so controllers and `IngestRateUsage` are unchanged; the `ingest` limiter falls back to this extractor so these clients get a bucket per credential.

## CORS is per project, and this middleware is its only voice
`config/cors.php` covers `api/v1/*` only. The DSN routes are excluded on purpose: a wildcard there would let any site post with a public key lifted from someone's page source. `HandleEnvelopeCors` (alias `envelope.cors`) answers them from `projects.allowed_origins` instead, echoing the origin rather than sending `*`, and always setting `Vary: Origin`. Do not add these paths back to `cors.php` — two middlewares writing the same header is how one of them silently wins.

The preflight route (`Route::options`) runs with `envelope.cors` and nothing else: it carries no credentials, so the key comes from the `sentry_key` query parameter, and a throttled preflight would fail the request it is asking permission for. An unknown key or an unlisted origin is answered without the header, never with a 4xx.

## Mapping rules
Only `event` items are stored. Sessions, transactions, attachments and client reports are counted as skipped, never errored — and, like every ingest path, a malformed body is a 200, never a 400 (ingest.md).

`exception.values` arrives oldest cause first, so the *last* entry is what was thrown; frames are reversed so the stacktrace attribute reads most-recent-first. Attributes are written under OpenTelemetry names (`event.id`, `service.version`, `deployment.environment`, `host.name`, `telemetry.sdk.*`, `process.runtime.*`, `exception.*`), never the wire format's — an event lands in the same table as everything else and is searched the same way.
