#!/bin/sh
# Bilis Linux server agent installer.
#
#   curl -fsSL __BILIS_ORIGIN__/install.sh | sudo BILIS_API_KEY=bilis_... sh
#   curl -fsSL __BILIS_ORIGIN__/install.sh | sudo sh -s -- --uninstall
#
# Installs a pinned, checksum-verified OpenTelemetry Collector (otelcol-contrib)
# as the systemd service `bilis-agent`, sending this server's host metrics,
# journald logs, (when Docker is present) container stats and (with --check)
# HTTP checks of local services to Bilis, plus a small `bilis-agent` command to
# check on it. Everything it writes:
#
#   /opt/bilis-agent/otelcol-contrib           the collector binary
#   /etc/bilis-agent/config.yaml               collector config (no secrets)
#   /etc/bilis-agent/agent.env                 API key and settings, mode 0600
#   /etc/bilis-agent/checks                    --check URLs, one per line
#   /var/lib/bilis-agent/                      journald cursor + send queue
#   /etc/systemd/system/bilis-agent.service    the service
#   /usr/local/bin/bilis-agent                 status / logs / test / update / uninstall
#
# Docs: __BILIS_ORIGIN__/docs/ingestion/server-agent
#
# This file is served by Bilis itself (GET /install.sh). The pinned version and
# its SHA-256 sums below are bumped together — see .ai/rules/install.md.

set -eu

BILIS_ORIGIN='__BILIS_ORIGIN__'

OTELCOL_VERSION='0.159.0'
OTELCOL_SHA256_AMD64='9d589f6349f01179957a2052bc7307a99db2efc971e14e00575941a77122eaaf'
OTELCOL_SHA256_ARM64='abb8665cc963e886c2d1286c50b38bcb2e53d968b192c3d8fe4d1ed6b91c3901'

AGENT_USER='bilis-agent'
INSTALL_DIR='/opt/bilis-agent'
CONFIG_DIR='/etc/bilis-agent'
STATE_DIR='/var/lib/bilis-agent'
UNIT_FILE='/etc/systemd/system/bilis-agent.service'
CLI_FILE='/usr/local/bin/bilis-agent'
BINARY="$INSTALL_DIR/otelcol-contrib"
CONFIG_FILE="$CONFIG_DIR/config.yaml"
ENV_FILE="$CONFIG_DIR/agent.env"
CHECKS_FILE="$CONFIG_DIR/checks"

# ---------------------------------------------------------------- output

if [ -t 1 ]; then
    BOLD="$(printf '\033[1m')"
    DIM="$(printf '\033[2m')"
    RED="$(printf '\033[31m')"
    RESET="$(printf '\033[0m')"
else
    BOLD='' DIM='' RED='' RESET=''
fi

say() { printf '%s\n' "$*"; }
step() { printf '%s==>%s %s\n' "$BOLD" "$RESET" "$*"; }
note() { printf '    %s%s%s\n' "$DIM" "$*" "$RESET"; }
fail() {
    printf '%serror:%s %s\n' "$RED" "$RESET" "$*" >&2
    exit 1
}

usage() {
    cat <<EOF
Bilis Linux server agent installer

Usage:
  curl -fsSL $BILIS_ORIGIN/install.sh | sudo BILIS_API_KEY=bilis_... sh
  curl -fsSL $BILIS_ORIGIN/install.sh | sudo BILIS_API_KEY=bilis_... sh -s -- [options]

Options:
  --key KEY          API key (or set BILIS_API_KEY). Kept from a previous install if omitted.
  --host-name NAME   host.name to report (default: the OS hostname)
  --interval DUR     metrics collection interval, e.g. 30s, 60s, 5m (default: 60s)
  --no-docker        do not collect Docker container stats, even if Docker is present
  --no-logs          do not ship journald logs
  --check URL        check this http(s) URL every interval (repeatable); replaces the
                     URLs of a previous install. Shown in Bilis as service 'uptime'.
  --no-checks        drop the URLs a previous install was given
  --dry-run          print what would be installed and the config; change nothing
  --uninstall        stop and remove the agent (keeps $STATE_DIR)
  --purge            with --uninstall, also remove $STATE_DIR
  -h, --help         this help
EOF
}

# ---------------------------------------------------------------- arguments

API_KEY="${BILIS_API_KEY:-}"
ENDPOINT="${BILIS_ENDPOINT:-$BILIS_ORIGIN}"
HOST_NAME="${BILIS_HOST_NAME:-}"
INTERVAL="${BILIS_INTERVAL:-}"
WITH_DOCKER='auto'
WITH_LOGS='yes'
CHECKS=''
CHECKS_GIVEN='no'
DRY_RUN='no'
UNINSTALL='no'
PURGE='no'

while [ $# -gt 0 ]; do
    case "$1" in
        --key) [ $# -ge 2 ] || fail '--key needs a value'; API_KEY="$2"; shift 2 ;;
        --key=*) API_KEY="${1#*=}"; shift ;;
        --host-name) [ $# -ge 2 ] || fail '--host-name needs a value'; HOST_NAME="$2"; shift 2 ;;
        --host-name=*) HOST_NAME="${1#*=}"; shift ;;
        --interval) [ $# -ge 2 ] || fail '--interval needs a value'; INTERVAL="$2"; shift 2 ;;
        --interval=*) INTERVAL="${1#*=}"; shift ;;
        --no-docker) WITH_DOCKER='no'; shift ;;
        --no-logs) WITH_LOGS='no'; shift ;;
        --check) [ $# -ge 2 ] || fail '--check needs a URL'; CHECKS="$CHECKS $2"; CHECKS_GIVEN='yes'; shift 2 ;;
        --check=*) CHECKS="$CHECKS ${1#*=}"; CHECKS_GIVEN='yes'; shift ;;
        --no-checks) CHECKS=''; CHECKS_GIVEN='yes'; shift ;;
        --dry-run) DRY_RUN='yes'; shift ;;
        --uninstall) UNINSTALL='yes'; shift ;;
        --purge) PURGE='yes'; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; fail "unknown option: $1" ;;
    esac
done

ENDPOINT="${ENDPOINT%/}"

# ---------------------------------------------------------------- checks

require_root() {
    [ "$(id -u)" -eq 0 ] || fail 'run this as root (pipe it to "sudo sh").'
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "$1 is required but not installed."
}

detect_arch() {
    case "$(uname -m)" in
        x86_64 | amd64) ARCH='amd64'; SHA256="$OTELCOL_SHA256_AMD64" ;;
        aarch64 | arm64) ARCH='arm64'; SHA256="$OTELCOL_SHA256_ARM64" ;;
        *) fail "unsupported architecture $(uname -m); the agent supports amd64 and arm64." ;;
    esac
}

preflight() {
    [ "$(uname -s)" = 'Linux' ] || fail 'the Bilis agent runs on Linux only.'
    require_root
    command -v systemctl >/dev/null 2>&1 || fail 'systemd is required (systemctl not found).'
    [ -d /run/systemd/system ] || fail 'systemd is not running as the init system here.'
    for tool in curl tar sha256sum mktemp; do
        require_command "$tool"
    done
    detect_arch
}

# A check URL is written into config.yaml (world-readable) inside double
# quotes, and the Collector expands ${...} anywhere in it: so http(s) only, and
# nothing that could close the quote, start an expansion or carry credentials.
valid_check_url() {
    case "$1" in
        http://?* | https://?*) ;;
        *) return 1 ;;
    esac
    case "$1" in
        *[!][A-Za-z0-9._~:/?#@!\&\(\)*+,\;=%-]*) return 1 ;;
    esac
    rest="${1#*://}"
    authority="${rest%%[/?#]*}"
    case "$authority" in
        '' | *@*) return 1 ;;
    esac
    return 0
}

valid_interval() {
    case "$1" in
        *[!0-9smh]* | '' | [!0-9]*) return 1 ;;
        *[0-9]s | *[0-9]m | *[0-9]h) return 0 ;;
        *) return 1 ;;
    esac
}

# ---------------------------------------------------------------- uninstall

uninstall() {
    require_root
    step 'Removing the Bilis agent'

    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now bilis-agent.service >/dev/null 2>&1 || true
    fi

    rm -f "$UNIT_FILE" "$CLI_FILE"
    rm -rf "$INSTALL_DIR" "$CONFIG_DIR"

    if command -v systemctl >/dev/null 2>&1; then
        systemctl daemon-reload >/dev/null 2>&1 || true
    fi

    if [ "$PURGE" = 'yes' ]; then
        rm -rf "$STATE_DIR"
        if id "$AGENT_USER" >/dev/null 2>&1; then
            userdel "$AGENT_USER" >/dev/null 2>&1 || true
        fi
        note "removed $STATE_DIR and the $AGENT_USER user"
    else
        note "kept $STATE_DIR (queued data and the journald cursor); --purge removes it"
    fi

    say 'The Bilis agent is uninstalled.'
}

if [ "$UNINSTALL" = 'yes' ]; then
    uninstall
    exit 0
fi

# ---------------------------------------------------------------- settings

preflight

# A re-run upgrades in place: keep whatever the last install was given unless
# this run says otherwise. The env file is root-only, and so is this script.
if [ -f "$ENV_FILE" ]; then
    previous() { sed -n "s/^$1=//p" "$ENV_FILE" | head -n 1; }
    [ -n "$API_KEY" ] || API_KEY="$(previous BILIS_API_KEY)"
    [ -n "$HOST_NAME" ] || HOST_NAME="$(previous BILIS_HOST_NAME)"
    [ -n "$INTERVAL" ] || INTERVAL="$(previous BILIS_INTERVAL)"
fi

if [ "$CHECKS_GIVEN" = 'no' ] && [ -f "$CHECKS_FILE" ]; then
    CHECKS="$(tr '\n' ' ' <"$CHECKS_FILE")"
fi

if [ -z "$API_KEY" ] && [ -r /dev/tty ] && [ "$DRY_RUN" = 'no' ]; then
    printf 'Bilis API key (bilis_...): ' >/dev/tty
    stty -echo </dev/tty 2>/dev/null || true
    IFS= read -r API_KEY </dev/tty || true
    stty echo </dev/tty 2>/dev/null || true
    printf '\n' >/dev/tty
fi

[ -n "$API_KEY" ] || fail "no API key. Pass BILIS_API_KEY=bilis_... (create one under Projects in $BILIS_ORIGIN)."

case "$API_KEY" in
    bilis_pk_*) fail 'that is the public half of a key (bilis_pk_...); use the secret key (bilis_...).' ;;
    bilis_*) ;;
    *) fail 'that does not look like a Bilis API key (they start with bilis_).' ;;
esac

case "$API_KEY" in
    *[!A-Za-z0-9_]*) fail 'the API key contains characters a Bilis key never has.' ;;
esac

[ -n "$INTERVAL" ] || INTERVAL='60s'
valid_interval "$INTERVAL" || fail "--interval must look like 30s, 60s or 5m (got '$INTERVAL')."

case "$HOST_NAME" in
    *[!A-Za-z0-9._-]*) fail "--host-name may contain letters, digits, dots, dashes and underscores only." ;;
esac

for url in $CHECKS; do
    valid_check_url "$url" || fail "--check must be a plain http(s) URL without credentials, quotes or \$ (got '$url')."
done

if [ "$WITH_DOCKER" = 'auto' ]; then
    if [ -S /var/run/docker.sock ]; then WITH_DOCKER='yes'; else WITH_DOCKER='no'; fi
fi

if [ "$WITH_LOGS" = 'yes' ] && ! command -v journalctl >/dev/null 2>&1; then
    note 'journalctl not found: journald logs will not be shipped.'
    WITH_LOGS='no'
fi

# Inside a container (LXC/OpenVZ VPS plans, OrbStack machines) the root
# filesystem sits on a block device the scraper cannot see, so it is classed as
# virtual and silently skipped. There, include virtual filesystems but report
# the root mount only; on a VM or bare metal, every real mount.
FS_INCLUDE_VIRTUAL='false'
FS_MOUNT_POINTS='.*'
VIRT="$(systemd-detect-virt --container 2>/dev/null || true)"
if [ -n "$VIRT" ] && [ "$VIRT" != 'none' ]; then
    FS_INCLUDE_VIRTUAL='true'
    FS_MOUNT_POINTS='^/$'
fi

# ---------------------------------------------------------------- verify the key

# Before anything on this machine changes: is the key good, and does this Bilis
# take protobuf? An empty export is a valid OTLP request that stores nothing.
ENCODING='proto'

http_status() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 15 \
        -H "Authorization: Bearer $API_KEY" -H "Content-Type: $1" \
        --data-binary "$2" "$ENDPOINT/api/v1/metrics" || true
}

step "Checking the API key against $ENDPOINT"

status="$(http_status 'application/json' '{"resourceMetrics":[]}')"
case "$status" in
    200) note 'key accepted' ;;
    401) fail 'Bilis refused the API key (401). Check it, or create a new one under Projects.' ;;
    429) fail 'Bilis is rate limiting this address (429). Wait a minute and try again.' ;;
    000) fail "could not reach $ENDPOINT. Check DNS, firewalls, or set BILIS_ENDPOINT." ;;
    *) fail "unexpected answer from $ENDPOINT/api/v1/metrics (HTTP $status)." ;;
esac

if [ "$(http_status 'application/x-protobuf' '')" = '415' ]; then
    ENCODING='json'
    note 'this Bilis accepts OTLP/JSON only; the agent will send JSON'
fi

# ---------------------------------------------------------------- config

write_config() {
    cat <<'EOF'
# Written by the Bilis agent installer. Re-running the installer rewrites it.
# Settings (key, endpoint, host name, interval) live in agent.env.

extensions:
    # Keeps the journald cursor and the send queue across restarts.
    file_storage:
        directory: /var/lib/bilis-agent

receivers:
    host_metrics:
        collection_interval: ${env:BILIS_INTERVAL}
        # The *.utilization gauges are off in the scraper by default; they are
        # what the Hosts tab charts as percentages.
        scrapers:
            cpu:
                metrics:
                    system.cpu.utilization:
                        enabled: true
            memory:
                metrics:
                    system.memory.utilization:
                        enabled: true
            load: {}
            paging: {}
            processes: {}
            disk:
                exclude:
                    devices: ['^loop.*', '^ram.*', '^zram.*', '^sr[0-9]+$']
                    match_type: regexp
            filesystem:
                metrics:
                    system.filesystem.utilization:
                        enabled: true
                include_virtual_filesystems: ${env:BILIS_FS_INCLUDE_VIRTUAL}
                include_mount_points:
                    mount_points: ['${env:BILIS_FS_MOUNT_POINTS}']
                    match_type: regexp
                exclude_fs_types:
                    fs_types: [autofs, binfmt_misc, bpf, cgroup, cgroup2, configfs, debugfs, devpts, devtmpfs, fusectl, hugetlbfs, mqueue, nsfs, overlay, proc, pstore, ramfs, rpc_pipefs, securityfs, squashfs, sysfs, tmpfs, tracefs]
                    match_type: strict
                exclude_mount_points:
                    mount_points: ['^/(dev|proc|run|sys|snap)(/|$)', '^/var/lib/docker/']
                    match_type: regexp
            network:
                exclude:
                    interfaces: ['^lo$', '^veth.*', '^docker.*', '^br-.*']
                    match_type: regexp
EOF

    if [ "$WITH_LOGS" = 'yes' ]; then
        cat <<'EOF'

    journald:
        priority: info
        start_at: end
        storage: file_storage
        operators:
            # The agent's own lines stay in `bilis-agent logs`: shipping them
            # would only echo an export failure through the export that failed.
            - type: filter
              expr: 'body._SYSTEMD_UNIT == "bilis-agent.service"'
            - type: severity_parser
              parse_from: body.PRIORITY
              overwrite_text: true
              mapping:
                  fatal: ['0', '1', '2']
                  error: '3'
                  warn: '4'
                  info2: '5'
                  info: '6'
                  debug: '7'
            # One service per systemd unit (nginx, ssh, cron...); kernel and
            # other unit-less lines fall back to their syslog identifier.
            - type: add
              if: 'body._SYSTEMD_UNIT != nil'
              field: resource["service.name"]
              value: 'EXPR(trimSuffix(body._SYSTEMD_UNIT, ".service"))'
            - type: add
              if: 'body._SYSTEMD_UNIT == nil and body.SYSLOG_IDENTIFIER != nil'
              field: resource["service.name"]
              value: 'EXPR(body.SYSLOG_IDENTIFIER)'
            - type: move
              if: 'body.SYSLOG_IDENTIFIER != nil'
              from: body.SYSLOG_IDENTIFIER
              to: attributes["syslog.identifier"]
            - type: move
              if: 'body._PID != nil'
              from: body._PID
              to: attributes["process.pid"]
            - type: move
              if: 'body._COMM != nil'
              from: body._COMM
              to: attributes["process.command"]
            - type: move
              if: 'body.MESSAGE != nil'
              from: body.MESSAGE
              to: body
EOF
    fi

    if [ "$WITH_DOCKER" = 'yes' ]; then
        cat <<'EOF'

    docker_stats:
        endpoint: unix:///var/run/docker.sock
        collection_interval: ${env:BILIS_INTERVAL}
EOF
    fi

    if [ -n "$CHECKS" ]; then
        cat <<'EOF'

    http_check:
        collection_interval: ${env:BILIS_INTERVAL}
        metrics:
            httpcheck.tls.cert_remaining:
                enabled: true
        targets:
EOF
        for url in $CHECKS; do
            printf '            - endpoint: "%s"\n' "$url"
        done
    fi

    cat <<'EOF'

processors:
    memory_limiter:
        check_interval: 5s
        limit_mib: 192
        spike_limit_mib: 48

    # host.name comes from the OS, unless BILIS_HOST_NAME set it through
    # OTEL_RESOURCE_ATTRIBUTES (the env detector runs first, and wins).
    resource_detection:
        detectors: [env, system]
        system:
            hostname_sources: [os]

    resource/host:
        attributes:
            - key: service.name
              value: host
              action: upsert

    resource/docker:
        attributes:
            - key: service.name
              value: docker
              action: upsert

    resource/uptime:
        attributes:
            - key: service.name
              value: uptime
              action: upsert

    # httpcheck.status writes one point per status class and four of the five
    # are always 0; the class that matched is the only one worth storing.
    filter/uptime:
        error_mode: ignore
        metrics:
            datapoint:
                - 'metric.name == "httpcheck.status" and value_int == 0'

    batch:
        timeout: 10s

exporters:
    # Explicit per-signal URLs: the base `endpoint` setting would append
    # /v1/metrics to the host, which is not where Bilis listens.
    otlp_http/bilis:
        metrics_endpoint: ${env:BILIS_ENDPOINT}/api/v1/metrics
        logs_endpoint: ${env:BILIS_ENDPOINT}/api/v1/logs
        encoding: ${env:BILIS_ENCODING}
        compression: gzip
        headers:
            Authorization: Bearer ${env:BILIS_API_KEY}
        sending_queue:
            enabled: true
            storage: file_storage
        retry_on_failure:
            enabled: true

service:
    extensions: [file_storage]
    telemetry:
        # No listening port on this machine.
        metrics:
            level: none
        logs:
            level: info
    pipelines:
        metrics/host:
            receivers: [host_metrics]
            processors: [memory_limiter, resource_detection, resource/host, batch]
            exporters: [otlp_http/bilis]
EOF

    if [ "$WITH_DOCKER" = 'yes' ]; then
        cat <<'EOF'
        metrics/docker:
            receivers: [docker_stats]
            processors: [memory_limiter, resource_detection, resource/docker, batch]
            exporters: [otlp_http/bilis]
EOF
    fi

    if [ -n "$CHECKS" ]; then
        cat <<'EOF'
        metrics/uptime:
            receivers: [http_check]
            processors: [memory_limiter, filter/uptime, resource_detection, resource/uptime, batch]
            exporters: [otlp_http/bilis]
EOF
    fi

    if [ "$WITH_LOGS" = 'yes' ]; then
        cat <<'EOF'
        logs/journald:
            receivers: [journald]
            processors: [memory_limiter, resource_detection, batch]
            exporters: [otlp_http/bilis]
EOF
    fi
}

write_env() {
    printf '# Bilis agent settings. Root-only: this holds the API key.\n'
    printf 'BILIS_API_KEY=%s\n' "$API_KEY"
    printf 'BILIS_ENDPOINT=%s\n' "$ENDPOINT"
    printf 'BILIS_HOST_NAME=%s\n' "$HOST_NAME"
    printf 'BILIS_INTERVAL=%s\n' "$INTERVAL"
    printf 'BILIS_ENCODING=%s\n' "$ENCODING"
    printf 'BILIS_FS_INCLUDE_VIRTUAL=%s\n' "$FS_INCLUDE_VIRTUAL"
    printf 'BILIS_FS_MOUNT_POINTS=%s\n' "$FS_MOUNT_POINTS"
    if [ -n "$HOST_NAME" ]; then
        printf 'OTEL_RESOURCE_ATTRIBUTES=host.name=%s\n' "$HOST_NAME"
    fi
}

write_checks() {
    for url in $CHECKS; do
        printf '%s\n' "$url"
    done
}

write_unit() {
    groups='systemd-journal adm'
    if [ "$WITH_DOCKER" = 'yes' ]; then
        groups="$groups docker"
    fi

    cat <<EOF
# Written by the Bilis agent installer.
[Unit]
Description=Bilis agent (OpenTelemetry Collector)
Documentation=$BILIS_ORIGIN/docs/ingestion/server-agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$AGENT_USER
Group=$AGENT_USER
SupplementaryGroups=$groups
EnvironmentFile=$ENV_FILE
ExecStartPre=$BINARY validate --config=$CONFIG_FILE
ExecStart=$BINARY --config=$CONFIG_FILE
Restart=on-failure
RestartSec=5

NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=$STATE_DIR
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
CapabilityBoundingSet=
AmbientCapabilities=
MemoryMax=256M

[Install]
WantedBy=multi-user.target
EOF
}

write_cli() {
    cat <<'EOF'
#!/bin/sh
# bilis-agent: check on the Bilis agent. Written by the installer.
set -eu

ENV_FILE='/etc/bilis-agent/agent.env'
CONFIG_FILE='/etc/bilis-agent/config.yaml'
BINARY='/opt/bilis-agent/otelcol-contrib'

need_root() {
    [ "$(id -u)" -eq 0 ] || { echo "bilis-agent $1 needs root (try: sudo bilis-agent $1)" >&2; exit 1; }
}

setting() { sed -n "s/^$1=//p" "$ENV_FILE" | head -n 1; }

usage() {
    cat <<'USAGE'
Usage: bilis-agent <command>

  status               is it running, where it sends, recent export errors
  logs [-f]            the agent's own log (journald)
  test                 send one test data point to Bilis and show the answer
  restart              restart the service
  config               show the config and settings files
  update               re-run the installer from Bilis (keeps the key)
  uninstall [--purge]  stop and remove the agent
USAGE
}

command="${1:-status}"
[ $# -gt 0 ] && shift

case "$command" in
    status)
        need_root status
        systemctl --no-pager status bilis-agent.service || true
        echo
        echo "endpoint:  $(setting BILIS_ENDPOINT)"
        echo "host.name: $(setting BILIS_HOST_NAME || true)"
        echo "interval:  $(setting BILIS_INTERVAL)"
        echo "checks:    $(cat /etc/bilis-agent/checks 2>/dev/null | wc -l | tr -d ' ') URL(s)"
        echo "collector: $("$BINARY" --version 2>/dev/null || echo unknown)"
        errors="$(journalctl -u bilis-agent.service --since '-15min' --no-pager -o cat 2>/dev/null | grep -ci 'exporting failed\|dropping data' || true)"
        echo "export errors in the last 15 min: ${errors:-0}"
        ;;
    logs)
        exec journalctl -u bilis-agent.service --no-pager -n 200 "$@"
        ;;
    test)
        need_root test
        endpoint="$(setting BILIS_ENDPOINT)"
        key="$(setting BILIS_API_KEY)"
        host="$(setting BILIS_HOST_NAME)"
        [ -n "$host" ] || host="$(hostname)"
        now="$(date +%s)000000000"
        body="{\"resourceMetrics\":[{\"resource\":{\"attributes\":[{\"key\":\"service.name\",\"value\":{\"stringValue\":\"host\"}},{\"key\":\"host.name\",\"value\":{\"stringValue\":\"$host\"}}]},\"scopeMetrics\":[{\"scope\":{\"name\":\"bilis-agent\"},\"metrics\":[{\"name\":\"bilis.agent.test\",\"unit\":\"1\",\"gauge\":{\"dataPoints\":[{\"timeUnixNano\":\"$now\",\"asInt\":\"1\"}]}}]}]}]}"
        answer="$(curl -s -w '\n%{http_code}' --max-time 15 -H "Authorization: Bearer $key" -H 'Content-Type: application/json' --data-binary "$body" "$endpoint/api/v1/metrics" || printf '\n000')"
        code="$(printf '%s' "$answer" | tail -n 1)"
        if [ "$code" = '200' ] && ! printf '%s' "$answer" | grep -q rejected; then
            echo "Bilis accepted a test data point (bilis.agent.test, host.name=$host)."
        else
            echo "Bilis answered HTTP $code:" >&2
            printf '%s\n' "$answer" | sed '$d' >&2
            exit 1
        fi
        ;;
    restart)
        need_root restart
        systemctl restart bilis-agent.service
        echo 'restarted'
        ;;
    config)
        need_root config
        echo "# $CONFIG_FILE"
        cat "$CONFIG_FILE"
        echo
        echo "# $ENV_FILE (key hidden)"
        sed 's/^BILIS_API_KEY=.*/BILIS_API_KEY=bilis_********/' "$ENV_FILE"
        ;;
    update)
        need_root update
        curl -fsSL "$(setting BILIS_ENDPOINT)/install.sh" | sh -s -- "$@"
        ;;
    uninstall)
        need_root uninstall
        curl -fsSL "$(setting BILIS_ENDPOINT)/install.sh" | sh -s -- --uninstall "$@"
        ;;
    -h | --help | help)
        usage
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
EOF
}

if [ "$DRY_RUN" = 'yes' ]; then
    step 'Dry run: nothing will be changed'
    note "would install otelcol-contrib $OTELCOL_VERSION ($ARCH) to $BINARY"
    note "docker stats: $WITH_DOCKER, journald logs: $WITH_LOGS, interval: $INTERVAL, encoding: $ENCODING, container: ${VIRT:-none}"
    note "checks: $(printf '%s' "${CHECKS:- none}" | sed 's/^ //')"
    say
    write_config
    exit 0
fi

# ---------------------------------------------------------------- install

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT INT TERM

installed_version() {
    [ -x "$BINARY" ] && "$BINARY" --version 2>/dev/null | sed -n 's/.*version \([0-9.]*\).*/\1/p' | head -n 1
}

if [ "$(installed_version || true)" = "$OTELCOL_VERSION" ]; then
    step "otelcol-contrib $OTELCOL_VERSION is already installed"
else
    tarball="otelcol-contrib_${OTELCOL_VERSION}_linux_${ARCH}.tar.gz"
    url="https://github.com/open-telemetry/opentelemetry-collector-releases/releases/download/v${OTELCOL_VERSION}/${tarball}"

    step "Downloading otelcol-contrib $OTELCOL_VERSION ($ARCH, about 100 MB)"
    curl -fL --retry 3 --progress-bar -o "$TMP/$tarball" "$url" || fail "download failed: $url"

    step 'Verifying its SHA-256'
    actual="$(sha256sum "$TMP/$tarball" | cut -d ' ' -f 1)"
    [ "$actual" = "$SHA256" ] || fail "checksum mismatch for $tarball (expected $SHA256, got $actual). Nothing was installed."
    note "$actual"

    tar -xzf "$TMP/$tarball" -C "$TMP" otelcol-contrib || fail 'could not extract the collector.'
    install -d -m 0755 "$INSTALL_DIR"
    install -m 0755 "$TMP/otelcol-contrib" "$BINARY.new"
fi

step 'Writing the service'

if ! id "$AGENT_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$AGENT_USER" 2>/dev/null \
        || useradd -r -M -d "$STATE_DIR" -s /sbin/nologin "$AGENT_USER"
fi

for group in systemd-journal adm; do
    getent group "$group" >/dev/null 2>&1 || groupadd --system "$group"
done

if [ "$WITH_DOCKER" = 'yes' ] && ! getent group docker >/dev/null 2>&1; then
    note 'Docker socket found but no docker group: container stats disabled.'
    WITH_DOCKER='no'
fi

install -d -m 0755 "$CONFIG_DIR"
install -d -m 0750 -o "$AGENT_USER" -g "$AGENT_USER" "$STATE_DIR"

write_config >"$TMP/config.yaml"
(umask 077 && write_env >"$TMP/agent.env")
write_checks >"$TMP/checks"
write_unit >"$TMP/bilis-agent.service"
write_cli >"$TMP/bilis-agent"

systemctl stop bilis-agent.service >/dev/null 2>&1 || true

if [ -f "$BINARY.new" ]; then
    mv -f "$BINARY.new" "$BINARY"
fi

install -m 0644 "$TMP/config.yaml" "$CONFIG_FILE"
install -m 0600 -o root -g root "$TMP/agent.env" "$ENV_FILE"
if [ -n "$CHECKS" ]; then
    install -m 0644 "$TMP/checks" "$CHECKS_FILE"
else
    rm -f "$CHECKS_FILE"
fi
install -m 0644 "$TMP/bilis-agent.service" "$UNIT_FILE"
install -m 0755 "$TMP/bilis-agent" "$CLI_FILE"

step 'Starting bilis-agent'
systemctl daemon-reload
systemctl enable bilis-agent.service >/dev/null 2>&1
systemctl restart bilis-agent.service

# ---------------------------------------------------------------- confirm

started="$(date +%s)"
active='no'
while [ $(($(date +%s) - started)) -lt 20 ]; do
    if systemctl is-active --quiet bilis-agent.service; then
        active='yes'
        break
    fi
    sleep 1
done

if [ "$active" != 'yes' ]; then
    journalctl -u bilis-agent.service --no-pager -n 30 -o cat >&2 || true
    fail 'bilis-agent did not start; its log is above. Fix and re-run, or run: bilis-agent logs'
fi

# Give the first scrape and export a moment, then look for a failure.
sleep 12
if journalctl -u bilis-agent.service --since '-30s' --no-pager -o cat 2>/dev/null | grep -qi 'exporting failed'; then
    say
    say "${RED}bilis-agent is running, but its first export failed:${RESET}"
    journalctl -u bilis-agent.service --since '-30s' --no-pager -o cat | grep -i 'exporting failed' | tail -n 3
    say 'Check with: sudo bilis-agent status'
    exit 1
fi

say
say "${BOLD}The Bilis agent is running.${RESET}"
say "  Sending:  host metrics every $INTERVAL$([ "$WITH_DOCKER" = 'yes' ] && printf ', Docker container stats')$([ "$WITH_LOGS" = 'yes' ] && printf ', journald logs')"
if [ -n "$CHECKS" ]; then
    say "  Checking: $(printf '%s' "$CHECKS" | wc -w | tr -d ' ') URL(s) every $INTERVAL (service 'uptime')"
fi
say "  To:       $ENDPOINT"
say "  See it:   $ENDPOINT/dashboard  (Metrics -> Hosts)"
say "  Manage:   sudo bilis-agent status | logs | test | update | uninstall"
if [ "$WITH_DOCKER" = 'yes' ]; then
    say "  ${DIM}Note: reading the Docker socket is root-equivalent; --no-docker turns it off.${RESET}"
fi
