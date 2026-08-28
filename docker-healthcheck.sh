#!/usr/bin/env sh
set -eu

# One image, three roles — and three different meanings of "healthy".
#
# The web role answers HTTP; the horizon and scheduler roles never open a
# port, so probing `/up` inside them fails forever and the platform rolls the
# deployment back with an empty healthcheck log. Each role is checked for what
# it actually is instead.
#
# The role is read from the file the entrypoint writes once it has resolved it
# (argv wins over BILIS_ROLE, and a healthcheck cannot see the container's
# argv), falling back to the environment for the window before the entrypoint
# has run.
role_file="${BILIS_ROLE_FILE:-/tmp/bilis-role}"

if [ -r "$role_file" ]; then
    role="$(cat "$role_file")"
else
    role="${BILIS_ROLE:-web}"
fi

# Docker reserves every exit code but 0 (healthy) and 1 (unhealthy), so each
# branch normalises its own failure.
case "$role" in
    web)
        curl -fsS -o /dev/null --max-time 4 "http://127.0.0.1:${PORT:-8080}/up" || exit 1
        ;;
    horizon)
        # `horizon:status` exits 0 running, 1 paused, 2 inactive. A pause is an
        # operator's deliberate act — restarting the container would undo it,
        # so only "inactive" is a failure.
        php artisan horizon:status >/dev/null 2>&1 || [ "$?" = 1 ] || exit 1
        ;;
    scheduler)
        # `schedule:work` is PID 1: if it dies the container is already gone,
        # so there is nothing left for a probe to catch. Booting the framework
        # is the only signal available, and it does catch a container that
        # starts but cannot read its config.
        php artisan schedule:list >/dev/null 2>&1 || exit 1
        ;;
    *)
        # A verbatim command (`docker run bilis artisan migrate`) is not a
        # long-running service and has no health to report.
        exit 0
        ;;
esac
