---
paths:
  - docker-entrypoint.sh
  - docker-healthcheck.sh
---

# General

## One image, three roles, selected by argument or BILIS_ROLE
`docker-entrypoint.sh` dispatches to `web` (frankenphp), `horizon`, `scheduler` (schedule:work) or `artisan`. The role comes from argv when argv starts with a known role, otherwise from `BILIS_ROLE`, otherwise `web`. Anything else in argv is still run verbatim.

The Dockerfile has `CMD []` on purpose. Restoring `CMD ["web"]` fills the argv slot on every run and makes `BILIS_ROLE` unreachable — which is the whole point of it, for platforms (Coolify's Dockerfile build pack) whose only per-resource knob is the environment. `tests/Feature/DockerEntrypointTest.php` asserts this.

An unrecognised `BILIS_ROLE` exits 64 rather than defaulting to web: a typo would otherwise start a second web server and the only symptom would be a queue that never drains.

Flags only ride with the argument form (`horizon --environment=production`); `BILIS_ROLE` takes the role alone.

Deploy exactly one horizon and one scheduler container — Horizon forks its own workers (scale with `HORIZON_MAX_PROCESSES`), and two schedulers double-fire `autofix:scan`.

## The healthcheck is role-aware, because only `web` serves HTTP
`docker-healthcheck.sh` (image: `bilis-healthcheck`) switches on the role: `web` curls `/up`, `horizon` uses `horizon:status` (paused counts as healthy — restarting would undo an operator's pause; only "inactive" fails), `scheduler` boots the framework with `schedule:list` since `schedule:work` is PID 1 and its death already kills the container, and a verbatim command reports nothing.

A single `GET /up` healthcheck can never pass in the horizon or scheduler containers — Coolify reports three empty-log attempts and rolls the deployment back on a process that was running fine. The entrypoint writes the resolved role to `${BILIS_ROLE_FILE:-/tmp/bilis-role}` because the healthcheck runs in its own process and cannot see argv; it falls back to `BILIS_ROLE`. Only exit 0 and 1 are meaningful to Docker, so every branch normalises its failure to 1. `tests/Feature/DockerHealthcheckTest.php` covers all of this.
