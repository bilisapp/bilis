---
paths:
  - docker-entrypoint.sh
---

# General

## One image, three roles, selected by argument or BILIS_ROLE
`docker-entrypoint.sh` dispatches to `web` (frankenphp), `horizon`, `scheduler` (schedule:work) or `artisan`. The role comes from argv when argv starts with a known role, otherwise from `BILIS_ROLE`, otherwise `web`. Anything else in argv is still run verbatim.

The Dockerfile has `CMD []` on purpose. Restoring `CMD ["web"]` fills the argv slot on every run and makes `BILIS_ROLE` unreachable — which is the whole point of it, for platforms (Coolify's Dockerfile build pack) whose only per-resource knob is the environment. `tests/Feature/DockerEntrypointTest.php` asserts this.

An unrecognised `BILIS_ROLE` exits 64 rather than defaulting to web: a typo would otherwise start a second web server and the only symptom would be a queue that never drains.

Flags only ride with the argument form (`horizon --environment=production`); `BILIS_ROLE` takes the role alone.

Deploy exactly one horizon and one scheduler container — Horizon forks its own workers (scale with `HORIZON_MAX_PROCESSES`), and two schedulers double-fire `autofix:scan`.
