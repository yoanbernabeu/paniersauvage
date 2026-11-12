# syntax=docker/dockerfile:1

# Production image of Panier Sauvage: FrankenPHP (Caddy + PHP 8.5) in worker mode.
# Build: docker build -t panier-sauvage .

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5

# --- Base: runtime with the PHP extensions the application needs ---------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

WORKDIR /app

RUN set -eux; \
    install-php-extensions \
        apcu \
        gd \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    ; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/docker-entrypoint

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

# --- Builder: Composer dependencies and compiled assets ------------------------------------------
FROM base AS builder

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first: this layer is reused as long as the composer files do not change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist

COPY . ./

# dump-env compiles the committed .env (non-secret defaults only: every secret is empty there).
# Real values come from the container environment, checked by docker/entrypoint.sh at start-up.
RUN set -eux; \
    mkdir -p var/cache var/log var/share var/sessions public/uploads; \
    composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative; \
    composer dump-env prod; \
    php bin/console importmap:install; \
    php bin/console tailwind:build --minify; \
    php bin/console asset-map:compile; \
    php bin/console assets:install public; \
    php bin/console cache:clear; \
    rm -rf var/tailwind var/log/* docker

# --- Final image -----------------------------------------------------------------------------------
FROM base AS app

ARG APP_UID=1000
ARG APP_GID=1000

# Unprivileged user; the capability lets it bind ports 80 and 443.
RUN set -eux; \
    groupadd --gid "$APP_GID" app; \
    useradd --uid "$APP_UID" --gid app --no-create-home --home-dir /app --shell /usr/sbin/nologin app; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    mkdir -p /data/caddy /config/caddy; \
    chown -R app:app /data /config /app

COPY --from=builder --chown=app:app /app /app

USER app

VOLUME ["/app/public/uploads", "/app/var/sessions"]

EXPOSE 80 443 443/udp

# Caddy admin endpoint: only reachable from inside the container.
HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=5 \
    CMD curl --fail --silent --output /dev/null http://localhost:2019/metrics || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
