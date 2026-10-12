# syntax=docker/dockerfile:1

# Pinned because the reference host, an Intel Ivy Bridge Mac, cannot run
# x86-64-v3 (AVX2) instructions.
ARG FRANKENPHP_VERSION=1.13.1
ARG PHP_VERSION=8.5.11

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

# git and unzip are Composer's preferred way to fetch and unpack packages.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# intl: Filament. pcntl: graceful queue worker shutdown on SIGTERM.
# zip: Composer archives. opcache: already built in, enabled by the ini below.
RUN install-php-extensions intl pcntl zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/cv-tailor.ini
COPY docker/entrypoint.sh /usr/local/bin/cv-tailor-entrypoint

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# FrankenPHP binds a privileged port only as root; this stack runs unprivileged
# and listens on 8080, with Compose publishing it on the host.
ENV SERVER_NAME=":8080" \
    SERVER_ROOT=/app/public

WORKDIR /app

# uid 1000 so a bind-mounted checkout stays writable in the toolchain stage.
RUN useradd --uid 1000 --create-home --shell /bin/bash app \
    && mkdir -p /config /data /var/lib/cv-tailor/db /var/lib/cv-tailor/pdfs \
    && chown -R app:app /app /config /data /var/lib/cv-tailor

# Tests, Pint and Larastan run here over a bind-mounted checkout, so this stage
# carries the toolchain and none of the source.
FROM base AS toolchain
USER app

FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM base AS app
COPY --from=vendor --chown=app:app /app/vendor ./vendor
COPY --chown=app:app . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && php artisan filament:assets

USER app
ENTRYPOINT ["cv-tailor-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
