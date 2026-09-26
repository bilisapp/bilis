---
title: Linux server agent
description: One command installs a pinned, checksum-verified OpenTelemetry Collector as a hardened systemd service that sends host metrics, journald logs and Docker container stats.
order: 9
---

The fastest way to watch a Linux box. One command, run as root, installs the
official OpenTelemetry Collector as a systemd service called `bilis-agent` and
starts sending three things to one Bilis project:

- **Host metrics** — CPU, memory, load, disks, filesystems, network, paging and
  process counts.
- **System logs** — everything journald receives, one `service.name` per
  systemd unit.
- **Docker container stats** — CPU, memory, network and block I/O per
  container, when Docker is installed.

```bash
curl -fsSL https://your-bilis-host/install.sh | sudo BILIS_API_KEY=bilis_YOUR_API_KEY sh
```

The app fills this command in for you: the project page shows it with a
placeholder, and the dialog that appears when you create an
[API key](/docs/ingestion/api-keys) shows it with the real key — the only
moment the key exists in plaintext. The script checks the key against Bilis
**before** it changes anything on the machine, so a typo costs you nothing.

It is not a home-grown agent. It is the upstream `otelcol-contrib` binary with a
generated config, so everything below is standard Collector behaviour you can
read, change and debug with the Collector's own documentation. For file-based
logs this agent does not read — fail2ban, UFW, application log files — see the
manual [Linux host](/docs/ingestion/linux-host) recipe; the two run side by
side.

## Requirements

- Linux with **systemd** (Debian, Ubuntu, RHEL, Fedora, Amazon Linux, Arch…).
- **root** — the command runs under `sudo`.
- **amd64** or **arm64**.
- `curl` or `wget`, `tar` and `sha256sum`.
- Outbound HTTPS to your Bilis host. The agent opens **no listening ports**.

The script refuses early, with a message naming what is missing, if any of
these is not met.

## What gets installed

| Path                                      | What                                                                                                                                                     |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/opt/bilis-agent/otelcol-contrib`        | The official Collector release, pinned to **v0.159.0** and verified against a SHA-256 embedded in the script.                                            |
| `/etc/bilis-agent/config.yaml`            | The generated Collector config. Mode `0644` — it holds no secrets; the key is read from the environment.                                                 |
| `/etc/bilis-agent/agent.env`              | `BILIS_API_KEY`, `BILIS_ENDPOINT`, `BILIS_HOST_NAME` and `BILIS_INTERVAL`. Mode **`0600`**, owned by root, loaded by systemd through `EnvironmentFile=`. |
| `/var/lib/bilis-agent/`                   | The journald cursor and the persistent send queue, so nothing is lost across restarts or a Bilis outage.                                                 |
| `/etc/systemd/system/bilis-agent.service` | The hardened systemd unit.                                                                                                                               |
| `/usr/local/bin/bilis-agent`              | A small CLI to check on and manage the agent — see [below](#the-bilis-agent-command).                                                                    |

It also creates a system user, `bilis-agent`, with no login shell. Nothing else
on the machine is touched.

## What it collects

### Host metrics

The `host_metrics` receiver scrapes every **60 seconds** by default
(`--interval` changes it) with these scrapers: `cpu`, `memory`, `load`, `disk`,
`filesystem`, `network`, `paging` and `processes` (counts only — no
per-process series).

Noise that would multiply series without telling you anything is excluded:

- **Filesystems** of pseudo types: `tmpfs`, `overlay`, `squashfs`, `proc`,
  `sysfs`, `cgroup`, `devtmpfs`, `nsfs` and the like.
- **Disks** named `loop*` and `ram*`.
- **Network interfaces** `lo`, `veth*`, `docker*` and `br-*`.

If the server is itself a container — an LXC or OpenVZ VPS plan, or an OrbStack
machine; the installer asks `systemd-detect-virt --container` — the root
filesystem sits on a block device the container cannot see, and the collector
would silently skip it. There the agent reports the root mount (`/`) only, which
is the disk the plan actually gives you.

A typical server produces around **100–200 series**. At the default 60-second
interval that is roughly **150,000–300,000 data points a day** — well under
the hosted Free plan's 1,000,000 metric data points a day (see
[Limits and behavior](/docs/reference/limits-and-behavior)). Halving the
interval to `30s` doubles the count; every extra disk, filesystem or interface
adds a handful of series.

### journald logs

The `journald` receiver reads the system journal from the moment of install
(`start_at: end` — it does not replay history) at priority `info` and above:

- The message becomes the log body.
- The severity comes from the journal's `PRIORITY` field — `0` (emerg) is
  `FATAL`, `3` is `ERROR`, `4` is `WARN`, `6` is `INFO`, `7` is `DEBUG` — using
  the same mapping as [Severity](/docs/ingestion/severity).
- `service.name` is the systemd unit with `.service` stripped (`nginx`,
  `sshd`, `cron`), or the syslog identifier when a line has no unit.
- `_PID`, `_COMM` and `_HOSTNAME` are kept as attributes.

The journal cursor is stored under `/var/lib/bilis-agent`, so a restart picks
up where it stopped instead of skipping or duplicating lines. `--no-logs`
turns this pipeline off and keeps metrics only.

### Docker container stats

Only when `/var/run/docker.sock` exists **at install time**, the
`docker_stats` receiver reports per-container CPU, memory, network and block
I/O on the same interval as host metrics.

> **Warning:** reading the Docker socket is **root-equivalent** — anything
> that can talk to it can start a privileged container. To collect container
> stats, the agent's user joins the `docker` group, and the installer says so
> when it does. If you do not want that, install with `--no-docker`.

### HTTP checks of local services

With one or more `--check <url>`, the `http_check` receiver requests each URL
on the same interval as host metrics, from the machine itself — so it can reach
services that are not public (`http://localhost:8080/health`, an internal
admin port). Each check reports:

- `httpcheck.duration` — how long the request took, in ms.
- `httpcheck.status` — `1`, with the answer's `http.status_code` and
  `http.status_class` (`2xx`, `5xx`, …) as attributes.
- `httpcheck.error` — `1` when there was no HTTP answer at all (DNS failure,
  refused connection, timeout), with the reason in `error.message`.
- `httpcheck.tls.cert_remaining` — for `https://` URLs, seconds until the
  certificate expires.

A check made from the machine it watches cannot tell you that the machine is
down, or unreachable from outside — it answers "is the app up on this box?".

The URLs are kept in `/etc/bilis-agent/checks`, so a re-run keeps them. A run
with `--check` replaces the whole list; `--no-checks` removes it. Because they
end up in the world-readable collector config, a check URL may not carry
credentials (`user:pass@`), quotes, spaces, `$` or braces — use a
health endpoint that needs no credentials.

## Where it shows up in Bilis

| Source         | `service.name`                                      | Where to look                     |
| -------------- | --------------------------------------------------- | --------------------------------- |
| Host metrics   | `host`                                              | Metrics explorer                  |
| Docker stats   | `docker`                                            | Metrics explorer                  |
| HTTP checks    | `uptime`                                            | Metrics explorer, by `http.url`   |
| journald lines | the unit, e.g. `nginx`, `sshd`, `docker`, `systemd` | Logs, filtered by service or host |

Every signal carries a `host.name` resource attribute — the machine's hostname,
or `--host-name` when you set one. Install the agent on several machines with
the same key and group by `host.name` in the metrics explorer to put them on
one chart.

A good first chart: `system.cpu.time`, which the explorer draws as a
per-second rate, grouped by `state` — `user`, `system`, `iowait`, `idle` —
shows where the CPU is going. `system.memory.usage` grouped by `state` and
`system.filesystem.usage` grouped by `mountpoint` are the next two most
people want.

## Flags

Pass flags after `sh -s --`:

```bash
curl -fsSL https://your-bilis-host/install.sh \
    | sudo BILIS_API_KEY=bilis_YOUR_API_KEY sh -s -- --interval 30s --no-docker
```

| Flag                 | What it does                                                                                             |
| -------------------- | -------------------------------------------------------------------------------------------------------- |
| `--key <key>`        | The API key, instead of `BILIS_API_KEY`. Prefer the environment variable — it stays out of `ps`.         |
| `--host-name <name>` | Overrides the `host.name` attribute. Defaults to the machine's hostname.                                 |
| `--interval <dur>`   | The scrape interval for host metrics and Docker stats, e.g. `30s`, `2m`. Default `60s`.                  |
| `--no-docker`        | Never collect container stats, even when Docker is installed. The agent stays out of the `docker` group. |
| `--no-logs`          | Metrics only; no journald pipeline.                                                                      |
| `--check <url>`      | Request this `http(s)` URL every interval (repeatable). Replaces the URLs of a previous install.        |
| `--no-checks`        | Drop the check URLs a previous install was given.                                                        |
| `--dry-run`          | Print the config and every action it would take, and change nothing.                                     |
| `--uninstall`        | Remove the agent — the same as `bilis-agent uninstall`.                                                  |
| `--purge`            | With `--uninstall`, also delete `/var/lib/bilis-agent`.                                                  |

## The `bilis-agent` command

| Command                           | What it does                                                                                        |
| --------------------------------- | --------------------------------------------------------------------------------------------------- |
| `bilis-agent status`              | Whether the service is running, where it sends, and how many exports failed in the last 15 minutes. |
| `bilis-agent logs [-f]`           | The agent's own journal; `-f` follows it.                                                           |
| `bilis-agent test`                | Sends one test data point (`bilis.agent.test`) with the configured key and reports what came back.  |
| `bilis-agent restart`             | Restarts the service, after validating the config.                                                  |
| `bilis-agent config`              | Prints the generated Collector config.                                                              |
| `bilis-agent update`              | Re-runs the installer from your Bilis host, keeping the key — see [Updating](#updating).            |
| `bilis-agent uninstall [--purge]` | Removes the agent — see [Uninstalling](#uninstalling).                                              |

## Security model

- **A pinned, verified binary.** The script downloads `otelcol-contrib`
  **v0.159.0** from the official OpenTelemetry GitHub release and checks it
  against a SHA-256 for your architecture that is embedded in the script
  itself. A tampered download or a hostile mirror fails the install; nothing is
  fetched from Bilis except the script.
- **A dedicated user.** The Collector runs as `bilis-agent`, a system user
  without a login shell, in the `systemd-journal` and `adm` groups so it can read
  the journal — plus `docker` only when container stats are on.
- **systemd hardening.** `NoNewPrivileges`, `ProtectSystem=strict`,
  `ProtectHome=read-only`, `PrivateTmp`, `ProtectKernelTunables`,
  `ProtectControlGroups`, `RestrictSUIDSGID`, `LockPersonality`, an empty
  capability bounding set and a 256 MB memory ceiling. The only writable path is
  `/var/lib/bilis-agent`. `/proc` and `/sys` stay readable, because that is where
  host metrics come from.
- **The key lives in one file.** It is written only to
  `/etc/bilis-agent/agent.env`, mode `0600`, owned by root, and handed to the
  service through systemd's `EnvironmentFile=`. The config refers to it as
  `${env:BILIS_API_KEY}`, so it never appears in `config.yaml`, in the process
  arguments or in `ps`.
- **No listening ports.** The Collector's own metrics endpoint is disabled and
  its logs go to the journal. The agent only makes outbound HTTPS requests to
  your Bilis host.
- **A broken config fails loudly.** The unit validates the config before every
  start, so a bad edit stops the service with a clear error rather than
  flapping.

The key is an ingest key: it can write to one project and read nothing. If a
machine is compromised, [revoke the key](/docs/ingestion/api-keys) on the
project page and nothing else is exposed.

## Updating

Re-run the one-liner, or run `bilis-agent update`. The script upgrades in
place: without `BILIS_API_KEY` it keeps the key already in `agent.env`, stops
the service, swaps the files atomically and starts it again. The journal cursor
and the send queue survive, so no lines are lost or repeated.

Pass a new `BILIS_API_KEY` to rotate the key the same way.

## Uninstalling

```bash
sudo bilis-agent uninstall
```

This stops and disables the service and removes the unit, the binary, the CLI
and `/etc/bilis-agent`. It keeps `/var/lib/bilis-agent` (the cursor and any
unsent queue) so a reinstall picks up where it left off; add `--purge` to delete
that too. Data already in Bilis stays until its retention expires.

## Troubleshooting

Start with the agent's own two commands:

```bash
sudo bilis-agent test        # does Bilis accept the key?
sudo bilis-agent logs -f     # what is the Collector saying?
```

- **`401` from `bilis-agent test`** — the key is wrong or has been revoked.
  Create a new one and re-run the one-liner with it.
- **`415`** — the Bilis instance has OTLP protobuf turned off
  (`BILIS_OTLP_PROTOBUF=false`). The installer detects this and switches the
  exporter to JSON, so you should not see it after a fresh install.
- **`503`** — Bilis is temporarily unable to store data. Nothing to do: the
  agent keeps the batch in its persistent queue and retries, honouring
  `Retry-After`.
- **No metrics after a few minutes** — look for `Exporting failed` in
  `bilis-agent logs`. The exporter uses the full `…/api/v1/metrics` and
  `…/api/v1/logs` paths explicitly; if you edited the config, see the note on
  endpoints in [Metrics](/docs/ingestion/metrics).
- **No container stats** — Docker was not installed when the agent was, or you
  passed `--no-docker`. Re-run the one-liner once Docker is present.
- **The service will not start** — `systemctl status bilis-agent` shows the
  validation error from the pre-start config check.

## Relation to the Linux host recipe

This agent is the quick path: metrics plus the journal, in one line, managed by
systemd. The [Linux host](/docs/ingestion/linux-host) page is the advanced,
hands-on recipe — a Collector in Docker that tails files in `/var/log` and
parses fail2ban, UFW and auth lines into filterable attributes. Use that one
for file-based logs the journal does not see; use this one for everything else.
