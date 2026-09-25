---
paths:
  - 'resources/install/**'
  - app/Http/Controllers/InstallScriptController.php
---

# Server agent installer

## The installer is POSIX sh, served by Bilis, pinned by version and hash
`resources/install/install.sh` is served at `GET /install.sh` (`InstallScriptController`) with one placeholder, `__BILIS_ORIGIN__`, replaced by `config('app.url')` — never by anything from the request, so a spoofed Host header cannot aim someone's agent elsewhere; the controller refuses to serve if a `__BILIS_` placeholder survives. Keep it POSIX `sh` (dash/busybox run it): `sh -n` runs in `InstallScriptTest`, and run shellcheck before changing it — `docker run --rm -v "$PWD":/mnt koalaman/shellcheck:stable -s sh /mnt/<rendered script>`, and separately on the `write_cli` heredoc body, which shellcheck otherwise skips.

`OTELCOL_VERSION` and both `OTELCOL_SHA256_*` values change together, copied from the release's `.sha256` assets (`otelcol-contrib_<v>_linux_{amd64,arm64}.tar.gz.sha256`), and the test pins them. Keep the version in step with SCHEMA.md's pinned collector tag.

## The key only ever lives in the root-only env file
`/etc/bilis-agent/agent.env` (0600 root) is loaded by systemd `EnvironmentFile=`; the Collector config reads `${env:BILIS_API_KEY}`, so the key is never in `config.yaml`, the unit, or an argv `ps` can see. `bilis-agent config` masks it. Re-running the installer without a key reuses the stored one; passing a new one rotates it.

## Collector config traps, verified on a real systemd box
- Use the non-deprecated component names (`host_metrics`, `resource_detection`, `otlp_http`): 0.159.0 warns on every start for the old ones.
- The exporter sets `metrics_endpoint`/`logs_endpoint` explicitly: the base `endpoint` appends `/v1/<signal>`, which is not where Bilis listens.
- Inside a container (LXC/OpenVZ VPS, OrbStack machine; `systemd-detect-virt --container`) the filesystem scraper classes the root filesystem as virtual and silently emits nothing. The installer then sets `include_virtual_filesystems: true` with `include_mount_points: ['^/$']` — the root only, because including virtual filesystems unrestricted pulls in every bind mount.
- The agent's own journald lines are filtered out of the journald receiver: shipping them would echo an export failure through the export that failed.

## Prove a change end to end
Syntax and shellcheck are not enough: install it on a throwaway systemd box (`orb create ubuntu <name>`, map the Bilis host in its `/etc/hosts`), check `bilis-agent status`/`test`, rows in ClickHouse, and the `--dry-run`, re-run, `uninstall` and `--purge` paths, then delete the box.
