---
paths:
  - 'examples/**'
---

# Examples

## examples/error-shop is the SDK traffic generator for Issues
A static page (no build, no app dependency) that throws five kinds of errors and reports them through the pinned Sentry browser SDK (CDN bundle, 8.55.0). Serve with `php -S 127.0.0.1:8777 -t examples/error-shop`; the project must list http://127.0.0.1:8777 under Browser origins. The DSN is never committed — it comes from `?dsn=` or the page form (remembered in localStorage); user/release/env/region are query params so a scenario is a link. Excluded from eslint (plain scripts using the SDK global) and from the Docker image (.dockerignore). The SDK dedupes identical consecutive errors, so repeated clicks undercount — vary `user` or reload. Vendor names are fine here: like the docs, this is about the SDK, not app code.
