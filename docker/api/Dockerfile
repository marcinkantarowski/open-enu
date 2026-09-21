# =============================================================================
#  Symfony API - nginx + php-fpm under supervisord in a single container.
# =============================================================================
#  Build context is the REPOSITORY ROOT, so the kernel path package
#  (backend/kernel) is inside the context and composer can symlink it.
#
#  Two stages. `dev` bind-mounts the source and remaps the php-fpm user to the
#  developer; `prod` copies a built, dependency-complete tree and owns it.
# =============================================================================

FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
      bash curl git icu-dev libzip-dev postgresql-dev postgresql-client \
      nginx supervisor zip unzip linux-headers \
 && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pdo_pgsql zip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install redis apcu \
 && docker-php-ext-enable redis apcu \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/api/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/api/supervisord.conf   /etc/supervisord.conf

WORKDIR /app

# ─────────────────────────────────────────────────────────────────────────────
FROM base AS dev

# Align the php-fpm user with the host user.
#
# The source tree is bind-mounted, so anything the container writes - composer
# packages, generated config, migrations, cache - lands on the host owned by
# whoever wrote it. As root that means files the developer cannot edit without
# sudo, appearing every time they run `composer require`. Remapping www-data to
# the host's uid/gid makes container-written files owned by the developer.
#
# Dev only. Production has no bind mount and no host user to match.
ARG UID=1000
ARG GID=1000
RUN apk add --no-cache shadow \
 && groupmod -o -g "${GID}" www-data \
 && usermod  -o -u "${UID}" -g "${GID}" www-data \
 && apk del shadow

# Xdebug is installed but OFF. Enabled per-session with
#   XDEBUG_MODE=debug docker compose ... - leaving it on costs ~2x on every
# request and is the usual reason a "slow" dev stack is slow.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY docker/api/php/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/api/entrypoint.sh   /usr/local/bin/entrypoint

# Pre-create the paths that become named volumes, owned by the php-fpm user, so
# a fresh volume inherits the right ownership (see entrypoint.sh).
RUN mkdir -p /app/var /app/vendor && chown -R www-data:www-data /app/var /app/vendor

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    XDEBUG_MODE=off

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# ─────────────────────────────────────────────────────────────────────────────
FROM base AS prod

# No bind mount, no host user to match, no Xdebug, no dev dependencies.
#
# The whole application is COPIED IN and the image is then immutable: a deploy
# replaces the container rather than the files inside it, which is what makes
# `opcache.validate_timestamps=0` safe and what makes a rollback a tag change.
COPY docker/api/php/php.prod.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/api/entrypoint.sh    /usr/local/bin/entrypoint

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, on their own layer: application code changes on every
# deploy and vendor/ does not, so this layer is reused until composer.lock moves.
COPY backend/composer.json backend/composer.lock ./
COPY backend/kernel ./kernel
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress \
 && composer clear-cache

COPY backend/ ./

# `--classmap-authoritative` turns autoloading into an array lookup with no
# filesystem probing. Correct only because nothing generates classes at runtime
# here - if that ever changes, this is the line that breaks first.
RUN composer dump-autoload --no-dev --classmap-authoritative --optimize \
 && mkdir -p var/cache var/log var/storage \
 && chown -R www-data:www-data var

# Deliberately NOT warmed at build time: the cache bakes in environment that is
# only known on the server (database URL, domains, secrets). `provision.sh`
# warms it after the .env exists.

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
