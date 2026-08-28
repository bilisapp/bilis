# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24

FROM node:${NODE_VERSION}-bookworm-slim AS node-base

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS php-base

WORKDIR /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1 \
    LOG_CHANNEL=stderr

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
    && install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        redis \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=128M'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'upload_max_filesize=16M'; \
        echo 'post_max_size=16M'; \
        echo 'expose_php=Off'; \
        echo 'display_errors=Off'; \
        echo 'display_startup_errors=Off'; \
        echo 'session.cookie_httponly=1'; \
        echo 'session.cookie_samesite=Lax'; \
        echo 'session.use_strict_mode=1'; \
    } > "$PHP_INI_DIR/conf.d/zz-bilis-production.ini" \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM php-base AS php-deps

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader

FROM php-base AS app-source

COPY . .
COPY --from=php-deps /app/vendor ./vendor

RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form --no-interaction

FROM node-base AS assets

WORKDIR /app

ENV WAYFINDER_COMMAND=true

COPY package.json package-lock.json ./
RUN npm ci

COPY --from=app-source /app ./

RUN npm run build

FROM php-base AS production

ENV PORT=8080 \
    BILIS_OPTIMIZE_ON_STARTUP=true \
    BILIS_MIGRATE_ON_STARTUP=false

COPY . .
COPY --from=php-deps /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/bilis-entrypoint
COPY --chmod=755 docker-healthcheck.sh /usr/local/bin/bilis-healthcheck

RUN { \
        echo '{'; \
        echo '    admin off'; \
        echo '    frankenphp'; \
        echo '    servers {'; \
        echo '        trusted_proxies static private_ranges'; \
        echo '        client_ip_headers X-Forwarded-For'; \
        echo '    }'; \
        echo '}'; \
        echo; \
        echo ':{$PORT:8080} {'; \
        echo '    root * /app/public'; \
        echo '    encode zstd br gzip'; \
        echo; \
        echo '    # The application owns its security headers; the server owns'; \
        echo '    # its own fingerprint. Neither should advertise a version.'; \
        echo '    header {'; \
        echo '        -Server'; \
        echo '        -X-Powered-By'; \
        echo '    }'; \
        echo; \
        echo '    # Refuse oversized bodies at the edge, matching post_max_size,'; \
        echo '    # so a runaway exporter never reaches PHP.'; \
        echo '    request_body {'; \
        echo '        max_size 16MB'; \
        echo '    }'; \
        echo; \
        echo '    # Nothing dot-prefixed is ever a real file under public/.'; \
        echo '    @dotfiles path_regexp /\.[^/]+$'; \
        echo '    respond @dotfiles 404'; \
        echo; \
        echo '    @assets path /build/*'; \
        echo '    header @assets Cache-Control "public, max-age=31536000, immutable"'; \
        echo; \
        echo '    php_server'; \
        echo '}'; \
    } > /etc/caddy/Caddyfile \
    && mkdir -p \
        /config/caddy \
        /data/caddy \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        database \
    && touch database/database.sqlite \
    && composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
        database \
        /config/caddy \
        /data/caddy

USER www-data

EXPOSE 8080

# Role-aware: only the web role serves HTTP. Probing /up inside the horizon
# and scheduler containers can never pass, and the platform reads that as a
# failed deployment.
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD ["bilis-healthcheck"]

ENTRYPOINT ["bilis-entrypoint"]

# Deliberately empty rather than ["web"]. The entrypoint falls back to
# BILIS_ROLE when argv carries no role, and a CMD would occupy that slot on
# every run — making the environment variable dead on any platform that does
# not let you override the command, which is the case it exists for.
CMD []
