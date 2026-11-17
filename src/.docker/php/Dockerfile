# syntax=docker/dockerfile:1
# Marketplace API PHP image. Build context: src/ (repo app root).
#   dev  - FPM + CLI + Composer, runs as host UID/GID, source bind-mounted (compose.yaml)
#   prod - sealed image: code baked in, no dev deps, no Composer, runs as www-data (compose.prod.yaml)

# ---------------------------------------------------------------------------
# base: PHP + extensions shared by every target, so dev and prod can't drift.
# ---------------------------------------------------------------------------
FROM php:8.4.27-fpm-alpine3.24 AS base

# Extensions (each one justified in README.md):
#   pdo_pgsql - PostgreSQL driver for Eloquent/Query Builder
#   redis     - phpredis (C extension) for cache/session/queue; faster than Predis
#   intl      - locale-aware formatting / validation (prices, names) used by Laravel helpers
#   bcmath    - arbitrary-precision decimal math for money; never use floats for prices
#   opcache   - bytecode cache; not compiled into the base image for 8.4
#   pcntl     - signal handling so `queue:work` shuts down gracefully on SIGTERM
RUN set -eux; \
    # icu-data-full: without it only English locale data exists (de_DE/ro_RO prices format wrong).
    apk add --no-cache icu-libs icu-data-full libpq; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql intl bcmath opcache pcntl; \
    pecl install redis-6.3.0; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc; \
    # FPM never runs as root in any target, so the pool user/group directives must go.
    sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf

COPY .docker/php/fpm/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# dev: source is bind-mounted; container user = host user (file ownership).
# ---------------------------------------------------------------------------
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache unzip git
COPY --from=composer:2.11.2 /usr/bin/composer /usr/bin/composer
COPY .docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN set -eux; \
    addgroup -g "${GID}" app; \
    adduser -D -u "${UID}" -G app -h /home/app app

ENV COMPOSER_HOME=/home/app/.composer
USER app

# ---------------------------------------------------------------------------
# vendor: build-only stage; resolves production dependencies. Never shipped.
# ---------------------------------------------------------------------------
FROM base AS vendor

# unzip only: --prefer-dist + lock file never needs git (no zip extension installed).
RUN apk add --no-cache unzip
COPY --from=composer:2.11.2 /usr/bin/composer /usr/bin/composer

# Dependencies first: this layer stays cached until composer.json/lock change.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN set -eux; \
    rm -rf .docker; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction; \
    php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# prod: code owned by root (read-only to PHP); only storage + bootstrap/cache writable.
# ---------------------------------------------------------------------------
FROM base AS prod

COPY .docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --chmod=0755 .docker/php/entrypoint.prod.sh /usr/local/bin/entrypoint
COPY --from=vendor /var/www/html /var/www/html

RUN set -eux; \
    # The php base image makes /var/www/html www-data-owned and 1777; PHP must not create files in the app root.
    chown root:root /var/www/html; \
    chmod 0755 /var/www/html; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
