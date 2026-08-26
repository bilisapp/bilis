#!/usr/bin/env sh
set -eu

role="${1:-web}"

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

case "$role" in
    web)
        shift || true
        prepare_storage
        prepare_laravel
        exec frankenphp run --config /etc/caddy/Caddyfile "$@"
        ;;
    horizon)
        shift || true
        prepare_storage
        prepare_laravel
        exec php artisan horizon "$@"
        ;;
    scheduler)
        shift || true
        prepare_storage
        prepare_laravel
        exec php artisan schedule:work "$@"
        ;;
    artisan)
        shift || true
        prepare_storage
        exec php artisan "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
