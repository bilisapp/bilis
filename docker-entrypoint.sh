#!/usr/bin/env sh
set -eu

# Which of the three long-running processes this container is.
#
# One image, three roles: the web server, the Horizon master, and the
# scheduler. They can be selected two ways, because the platforms that run
# this disagree about which they offer:
#
#   docker run bilis horizon          — an argument, when the platform lets
#                                       you override the command.
#   BILIS_ROLE=horizon                — an environment variable, for platforms
#                                       (Coolify's Dockerfile build pack among
#                                       them) whose only per-resource knob is
#                                       the environment.
#
# An explicit argument wins, so `docker run bilis artisan migrate` still works
# on a host whose environment says `horizon`. With neither, it is the web role.
#
# Flags ride with the argument form (`horizon --environment=production`).
# `BILIS_ROLE` carries the role and nothing else: bare argv already means "run
# this command verbatim", and it cannot also mean "flags for the role in the
# environment".
KNOWN_ROLES="web horizon scheduler artisan"

is_known_role() {
    for known in $KNOWN_ROLES; do
        [ "$1" = "$known" ] && return 0
    done
    return 1
}

resolve_role() {
    if [ "$#" -gt 0 ] && is_known_role "$1"; then
        echo "$1"
        return 0
    fi

    # Anything else in argv is a command to run verbatim, not a role, and the
    # environment must not hijack it.
    if [ "$#" -gt 0 ]; then
        echo ""
        return 0
    fi

    role="${BILIS_ROLE:-web}"

    # A typo here is the worst kind of failure: `BILIS_ROLE=horzion` would
    # otherwise start a second web server, and the only symptom would be a
    # queue that never drains, hours later, with nothing in any log to say why.
    if ! is_known_role "$role"; then
        echo "bilis-entrypoint: unknown BILIS_ROLE '$role' (expected one of: $KNOWN_ROLES)" >&2
        exit 64
    fi

    echo "$role"
}

prepare_storage() {
    mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
        database_path="${DB_DATABASE:-/app/database/database.sqlite}"
        mkdir -p "$(dirname "$database_path")"
        touch "$database_path"
    fi
}

prepare_laravel() {
    if [ "${BILIS_OPTIMIZE_ON_STARTUP:-true}" = "true" ]; then
        php artisan optimize
    fi

    if [ "${BILIS_MIGRATE_ON_STARTUP:-false}" = "true" ]; then
        php artisan migrate --force
        php artisan clickhouse:migrate --no-interaction
    fi
}

role="$(resolve_role "$@")"

# The healthcheck runs in its own process with no view of this container's
# argv, so record the role it resolved to. Best effort: a read-only or
# unwritable path must not stop the service from starting — the healthcheck
# falls back to BILIS_ROLE.
role_file="${BILIS_ROLE_FILE:-/tmp/bilis-role}"
printf '%s' "$role" > "$role_file" 2>/dev/null || true

# Only consume an argument when it was one: with the role coming from the
# environment, argv is already the extra flags to pass through.
if [ "$#" -gt 0 ] && [ "$role" != "" ] && [ "$1" = "$role" ]; then
    shift
fi

case "$role" in
    web)
        prepare_storage
        prepare_laravel
        exec frankenphp run --config /etc/caddy/Caddyfile "$@"
        ;;
    horizon)
        prepare_storage
        prepare_laravel
        exec php artisan horizon "$@"
        ;;
    scheduler)
        prepare_storage
        prepare_laravel
        exec php artisan schedule:work "$@"
        ;;
    artisan)
        prepare_storage
        exec php artisan "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
