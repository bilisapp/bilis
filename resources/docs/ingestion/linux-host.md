---
title: Linux host
description: A Collector config that ships auth, syslog, fail2ban, UFW and container logs off a VPS with real event times and filterable attributes.
order: 8
---

This is the configuration running on the box that hosts Bilis itself: one
OpenTelemetry Collector in Docker, tailing the log files a Linux server already
writes, shipping them into one Bilis project. Nothing is installed on the host
beyond Docker — the collector reads `/var/log` read-only.

What you end up with:

- **Auth** (`/var/log/auth.log`) — every SSH attempt, sudo call and session.
- **Syslog** (`/var/log/syslog`) — everything else the system says, tagged with
  the program that said it (`syslog.appname`).
- **fail2ban** — with its own severities, so a ban is `NOTICE` and not lost in a
  wall of `INFO`, plus `fail2ban.jail`, `fail2ban.action` and `fail2ban.ip`.
- **UFW** — every firewall verdict once, not three times, with `ufw.src`,
  `ufw.dst`, `ufw.proto`, `ufw.spt`, `ufw.dpt`.
- **Docker containers** — the JSON log files Docker already writes, unwrapped
  back into plain messages.

Each of those becomes its own `service.name` in the viewer, so the service
filter is your "which part of the box" switch.

## 1. Create the project and key

In the app: **Projects → New project** (`vps-production` is a reasonable name),
then create an API key inside it. It looks like `bilis_…` and is shown once.
Put it in a `.env` file next to the compose file:

```bash
# .env
BILIS_API_KEY=bilis_YOUR_API_KEY
```

## 2. docker-compose.yml

```yaml
services:
    otel-collector:
        image: otel/opentelemetry-collector-contrib:latest
        container_name: bilis-otel-collector
        restart: unless-stopped
        user: '0:0'
        env_file:
            - .env
        command: ['--config=/etc/otelcol-contrib/config.yml']
        volumes:
            - ./config.yml:/etc/otelcol-contrib/config.yml:ro
            - /var/log:/var/log:ro
            - /var/lib/docker/containers:/var/lib/docker/containers:ro
            - otel-storage:/var/lib/otelcol/storage
        logging:
            driver: json-file
            options:
                max-size: '10m'
                max-file: '3'

volumes:
    otel-storage:
```

`user: "0:0"` is there because `/var/log/auth.log` is not world-readable. The
mounts are `:ro` — the collector can read your logs and nothing else. The
`otel-storage` volume is where the collector remembers how far it has read into
each file; see [Checkpoints](#why-the-storage-volume-matters) below for why
that volume is the difference between a restart being free and a restart
costing you every line written while the collector was down.

## 3. config.yml

Three things to change before you start it: `host.name` (`vps-8d4cfe56` below),
the `logs_endpoint` if your Bilis lives somewhere other than `bilis.app`, and
nothing else — the API key comes from the environment.

```yaml
extensions:
    # Persists filelog read checkpoints across restarts. Without this, every
    # restart jumps to the end of each file (start_at: end) and the lines
    # written while the collector was down are lost.
    file_storage:
        directory: /var/lib/otelcol/storage

receivers:
    filelog/auth:
        include: [/var/log/auth.log]
        start_at: end
        storage: file_storage
        include_file_path: true
        operators:
            # 2026-08-26T18:08:15.187811+00:00 vps-8d4cfe56 sshd-session[316724]: message...
            - type: regex_parser
              regex: '^(?P<ts>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+(?:[+-]\d{2}:\d{2}|Z))\s+(?P<hostname>\S+)\s+(?P<appname>[^\s:\[]+)(?:\[(?P<pid>\d+)\])?:\s?(?P<message>.*)$'
              on_error: send_quiet
              timestamp:
                  parse_from: attributes.ts
                  layout_type: gotime
                  layout: '2006-01-02T15:04:05.999999Z07:00'
            - type: move
              if: 'attributes.message != nil'
              from: attributes.message
              to: body
            - type: move
              if: 'attributes.appname != nil'
              from: attributes.appname
              to: attributes["syslog.appname"]
            - type: remove
              if: 'attributes.ts != nil'
              field: attributes.ts
            - type: remove
              if: 'attributes.hostname != nil'
              field: attributes.hostname

    # kern.log is intentionally NOT tailed: kernel messages already land in
    # syslog, and UFW kernel lines are ingested (and parsed) via filelog/ufw.
    filelog/syslog:
        include: [/var/log/syslog]
        start_at: end
        storage: file_storage
        include_file_path: true
        operators:
            - type: regex_parser
              regex: '^(?P<ts>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+(?:[+-]\d{2}:\d{2}|Z))\s+(?P<hostname>\S+)\s+(?P<appname>[^\s:\[]+)(?:\[(?P<pid>\d+)\])?:\s?(?P<message>.*)$'
              on_error: send_quiet
              timestamp:
                  parse_from: attributes.ts
                  layout_type: gotime
                  layout: '2006-01-02T15:04:05.999999Z07:00'
            - type: move
              if: 'attributes.message != nil'
              from: attributes.message
              to: body
            - type: move
              if: 'attributes.appname != nil'
              from: attributes.appname
              to: attributes["syslog.appname"]
            - type: remove
              if: 'attributes.ts != nil'
              field: attributes.ts
            - type: remove
              if: 'attributes.hostname != nil'
              field: attributes.hostname
            # UFW lines also reach syslog; drop them here so filelog/ufw is their
            # single source. (Alternative: uncomment "& stop" in
            # /etc/rsyslog.d/20-ufw.conf and delete this operator.)
            - type: filter
              expr: 'body matches "\\[UFW [A-Z ]+\\]"'

    filelog/fail2ban:
        include: [/var/log/fail2ban.log]
        start_at: end
        storage: file_storage
        include_file_path: true
        operators:
            # 2026-08-26 18:08:15,187 fail2ban.actions [575]: NOTICE [sshd] Ban 203.0.113.7
            - type: regex_parser
              regex: '^(?P<ts>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2},\d{3})\s+(?P<component>\S+)\s*(?:\[(?P<pid>\d+)\])?:\s+(?P<level>[A-Z]+)\s+(?P<message>.*)$'
              on_error: send_quiet
              timestamp:
                  parse_from: attributes.ts
                  layout_type: strptime
                  layout: '%Y-%m-%d %H:%M:%S,%L'
              severity:
                  parse_from: attributes.level
                  mapping:
                      debug: DEBUG
                      info: INFO
                      info2: NOTICE
                      warn: WARNING
                      error: ERROR
                      fatal: CRITICAL
            - type: move
              if: 'attributes.message != nil'
              from: attributes.message
              to: body
            - type: remove
              if: 'attributes.ts != nil'
              field: attributes.ts
            # "[sshd] Ban 203.0.113.7" -> jail / action / ip attributes
            - type: regex_parser
              parse_from: body
              regex: '^\[(?P<jail>[^\]]+)\]\s+(?P<action>Ban|Unban|Restore Ban|Found|Ignore)\s+(?P<ip>\S+)'
              on_error: send_quiet
            - type: move
              if: 'attributes.jail != nil'
              from: attributes.jail
              to: attributes["fail2ban.jail"]
            - type: move
              if: 'attributes.action != nil'
              from: attributes.action
              to: attributes["fail2ban.action"]
            - type: move
              if: 'attributes.ip != nil'
              from: attributes.ip
              to: attributes["fail2ban.ip"]

    filelog/ufw:
        include: [/var/log/ufw.log]
        start_at: end
        storage: file_storage
        include_file_path: true
        operators:
            - type: regex_parser
              regex: '^(?P<ts>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+(?:[+-]\d{2}:\d{2}|Z))\s+(?P<hostname>\S+)\s+kernel:\s?(?P<message>.*)$'
              on_error: send_quiet
              timestamp:
                  parse_from: attributes.ts
                  layout_type: gotime
                  layout: '2006-01-02T15:04:05.999999Z07:00'
            - type: move
              if: 'attributes.message != nil'
              from: attributes.message
              to: body
            - type: remove
              if: 'attributes.ts != nil'
              field: attributes.ts
            - type: remove
              if: 'attributes.hostname != nil'
              field: attributes.hostname
            # [UFW BLOCK] ... SRC=1.2.3.4 DST=5.6.7.8 ... PROTO=TCP SPT=51234 DPT=22
            - type: regex_parser
              parse_from: body
              regex: '\[UFW (?P<action>[A-Z ]+)\].*?SRC=(?P<src>\S+)\s+DST=(?P<dst>\S+).*?PROTO=(?P<proto>\S+)(?:.*?SPT=(?P<spt>\d+)\s+DPT=(?P<dpt>\d+))?'
              on_error: send_quiet
            - type: move
              if: 'attributes.action != nil'
              from: attributes.action
              to: attributes["ufw.action"]
            - type: move
              if: 'attributes.src != nil'
              from: attributes.src
              to: attributes["ufw.src"]
            - type: move
              if: 'attributes.dst != nil'
              from: attributes.dst
              to: attributes["ufw.dst"]
            - type: move
              if: 'attributes.proto != nil'
              from: attributes.proto
              to: attributes["ufw.proto"]
            - type: move
              if: 'attributes.spt != nil'
              from: attributes.spt
              to: attributes["ufw.spt"]
            - type: move
              if: 'attributes.dpt != nil'
              from: attributes.dpt
              to: attributes["ufw.dpt"]

    filelog/docker:
        include: [/var/lib/docker/containers/*/*.log]
        start_at: end
        storage: file_storage
        include_file_path: true
        operators:
            - type: json_parser
              parse_from: body
              timestamp:
                  parse_from: attributes.time
                  layout_type: gotime
                  layout: '2006-01-02T15:04:05.999999999Z07:00'
            - type: move
              from: attributes.log
              to: body
            - type: move
              from: attributes.stream
              to: attributes["log.iostream"]
            - type: remove
              if: 'attributes.time != nil'
              field: attributes.time

processors:
    memory_limiter:
        check_interval: 5s
        limit_mib: 256
        spike_limit_mib: 64

    resource/host:
        attributes:
            - key: host.name
              value: vps-8d4cfe56
              action: upsert
            - key: deployment.environment
              value: production
              action: upsert

    resource/auth:
        attributes:
            - key: service.name
              value: auth
              action: upsert

    resource/syslog:
        attributes:
            - key: service.name
              value: syslog
              action: upsert

    resource/fail2ban:
        attributes:
            - key: service.name
              value: fail2ban
              action: upsert

    resource/ufw:
        attributes:
            - key: service.name
              value: ufw
              action: upsert

    resource/docker:
        attributes:
            - key: service.name
              value: docker-containers
              action: upsert

    batch:
        timeout: 5s
        send_batch_size: 1024

exporters:
    otlphttp/bilis:
        logs_endpoint: https://bilis.app/api/v1/logs
        encoding: json
        compression: gzip # or none; both are understood
        headers:
            Authorization: Bearer ${env:BILIS_API_KEY}

service:
    extensions: [file_storage]
    pipelines:
        logs/auth:
            receivers: [filelog/auth]
            processors: [memory_limiter, resource/host, resource/auth, batch]
            exporters: [otlphttp/bilis]
        logs/syslog:
            receivers: [filelog/syslog]
            processors: [memory_limiter, resource/host, resource/syslog, batch]
            exporters: [otlphttp/bilis]
        logs/fail2ban:
            receivers: [filelog/fail2ban]
            processors:
                [memory_limiter, resource/host, resource/fail2ban, batch]
            exporters: [otlphttp/bilis]
        logs/ufw:
            receivers: [filelog/ufw]
            processors: [memory_limiter, resource/host, resource/ufw, batch]
            exporters: [otlphttp/bilis]
        logs/docker:
            receivers: [filelog/docker]
            processors: [memory_limiter, resource/host, resource/docker, batch]
            exporters: [otlphttp/bilis]
```

Then `docker compose up -d`, and `docker compose logs -f` for the first minute
to confirm it started clean.

## Why the timestamp blocks are not optional

Drop every `timestamp:` block and the collector still ships your logs — they
just all get the wrong time. A log file line carries its own time; without a
`timestamp:` block, Bilis stores the moment the collector **read** the line
instead. The batch processor ticks every few seconds, so the left column of the
viewer turns into clusters of rows sharing the same millisecond (`.130`, `.331`,
`.731`, over and over), the ordering within each cluster is arbitrary, and the
real time is sitting there duplicated at the front of the message text where you
cannot sort by it.

With the blocks in place, each row is stamped with the time the event actually
happened, at microsecond precision, and the duplicate timestamp header is
stripped out of the body by the `move` and `remove` operators that follow. The
log list is then genuinely a timeline. See
[Timestamps](/docs/ingestion/timestamps) for how Bilis normalises what it
receives.

## Why the UFW deduplication matters

By default rsyslog writes UFW's kernel lines to **three** places: `ufw.log`,
`kern.log` and `syslog`. Tail all three and every blocked packet arrives in
Bilis three times. Every count you look at afterwards — bans this hour, blocks
from one address, "is this port being scanned" — is inflated by a factor you
have to remember to divide out.

This config tails `syslog` and `ufw.log` only (never `kern.log`, whose contents
are already in `syslog`), and then filters UFW lines back out of the syslog
pipeline so `ufw.log` is their single source. Every firewall event lands exactly
once, fully parsed.

If you would rather fix it at the source, uncomment the `& stop` line in
`/etc/rsyslog.d/20-ufw.conf` — that stops rsyslog copying UFW lines onward — and
delete the `filter` operator from `filelog/syslog`. Do one or the other, not
neither.

## Why the storage volume matters

`start_at: end` says "when you first meet a file, skip what is already in it" —
which is what you want, because you are not trying to backfill a year of syslog.

Without the `file_storage` extension the collector has no memory, so _every_
start is a first meeting. Restart it, redeploy it, let the host reboot, and it
quietly jumps to the current end of every file. Everything written while it was
down is gone, with no error and no gap you would notice unless you went looking.

With `file_storage` pointed at the `otel-storage` volume, the collector writes a
checkpoint per file. `start_at: end` then applies only to the genuine first run;
after that it resumes at the exact byte where it stopped. Restarts become free.

## What the attributes buy you

The regex parsers are the difference between logs you read and logs you query.

- **fail2ban** rows carry `fail2ban.action` (`Ban`, `Unban`, `Found`, …),
  `fail2ban.jail` and `fail2ban.ip`, and take fail2ban's own level as their
  severity — a ban shows up as `NOTICE`, not swallowed by a blanket `INFO`. "How
  many bans this hour, and from where" becomes a filter instead of an eyeball
  scan of a wall of text.
- **UFW** rows carry `ufw.action`, `ufw.src`, `ufw.dst`, `ufw.proto`, `ufw.spt`
  and `ufw.dpt`, so "who is knocking on port 22" is a search, not a regex you
  retype every time.
- **syslog and auth** rows carry `syslog.appname`, which is how you separate
  `sshd` from `cron` from `systemd` inside one stream.

Every parser sets `on_error: send_quiet`. A line that does not match the pattern
is shipped **raw** rather than dropped or logged as an error — the same
best-effort posture the ingest endpoints take. You never lose a line because it
had an unexpected shape; you just get it unparsed. That is deliberate: see
[Endpoints](/docs/ingestion/endpoints).

## Checking it works

Restart the collector and watch the viewer:

- New rows show **one** timestamp — in the left column, not repeated at the
  start of the message.
- Filter the service to `fail2ban` and expand a row: `fail2ban.jail`,
  `fail2ban.action` and `fail2ban.ip` are there, with a real severity on the
  row.
- Filter to `ufw` and confirm a blocked packet appears **once**.

Rows that arrived before the change keep their old shape. The collector ships
forward only and never re-reads history, so the fix applies to new lines — do
not wait for the old ones to heal.

> **Note:** the receiver type is `filelog`, one word, and the exporter type is
> `otlphttp`, one word. `file_log` and `otlp_http` are the two typos that stop
> the collector from starting at all, and the error message points at the
> pipeline rather than the spelling.
