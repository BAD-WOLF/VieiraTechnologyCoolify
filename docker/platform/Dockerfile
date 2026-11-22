# Image of Hostrema: the Coolify of this fork with the platform (control plane).
#
# Built by .github/workflows/platform-release.yml and by infra/local. It follows the official
# docker/production/Dockerfile and reuses its configuration files and S6 services (copied from
# docker/production), so pulls from upstream keep flowing in without editing upstream files.
# What changes, and why:
# - Base serversideup/php v5, public and still patched. The official 4.x Dockerfile builds on a
#   private rebuild of v4 (unusable here) and every public v4 tag is frozen. The version below is
#   bumped by .github/workflows/platform-base-image.yml (major 5 only).
# - S6 services registered in user-bundles.d, as S6 Overlay 3.2.3.2 (in v5) requires.
# - PHP-FPM listens on 127.0.0.1 only (v5 listens on every interface) and nginx leaves the client
#   address to Laravel (TRUSTED_PROXY=off; Coolify trusts the proxy headers in TrustProxies).
# - OPcache keeps checking file times: app:init rebuilds the Laravel caches while PHP-FPM runs.
# tests/Feature/Platform/PlatformSettingsTest.php checks that every application path copied by the
# official Dockerfile is copied here too.

# https://github.com/serversideup/docker-php/releases
ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine-v5.1.2
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2026.10.3
# PostgreSQL 18 client for the PostgreSQL 15 server (the 15 client left Alpine 3.23).
ARG POSTGRES_VERSION=18

ARG USER_ID=9999
ARG GROUP_ID=9999

# =================================================================
# Stage 1: Composer dependencies
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS base

USER root

ARG USER_ID
ARG GROUP_ID

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    COMPOSER_CACHE_DIR=/tmp/cache composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

USER www-data

# =================================================================
# Stage 2: Frontend assets
# =================================================================
FROM node:24-alpine AS static-assets

WORKDIR /app
COPY package*.json vite.config.js postcss.config.cjs ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci
COPY . .
RUN npm run build

# =================================================================
# Stage 3: MinIO client (minio/mc is no longer published; the AGPL client of Coolify 4.0.0,
# as in the official v4.0.x Dockerfile)
# =================================================================
FROM ghcr.io/coollabsio/coolify:4.0.0 AS minio-client

# =================================================================
# Final stage
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG USER_ID
ARG GROUP_ID
ARG TARGETPLATFORM
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION
ARG SERVERSIDEUP_PHP_VERSION
ARG CI=true

WORKDIR /var/www/html

USER root

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# openssl: opens the .p12 certificates of Efí Bank, made with algorithms that PHP (OpenSSL 3)
# only reads through the command (-legacy).
RUN --mount=type=cache,target=/var/cache/apk \
    apk upgrade && \
    apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    openssh-client \
    openssl \
    git \
    git-lfs \
    jq \
    lsof \
    vim

RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

RUN mkdir -p /usr/local/bin && \
    if [ "${TARGETPLATFORM}" = "linux/amd64" ]; then \
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${TARGETPLATFORM}" = "linux/arm64" ]; then \
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    fi && \
    chmod +x /usr/local/bin/cloudflared

# PHP: the settings of the official image plus the v5 adjustments above.
COPY docker/production/etc/php/conf.d/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
COPY docker/platform/etc/php/conf.d/zzzz-hostrema.ini /usr/local/etc/php/conf.d/zzzz-hostrema.ini
COPY docker/platform/etc/php-fpm.d/zzzz-hostrema-listen.conf /usr/local/etc/php-fpm.d/zzzz-hostrema-listen.conf
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
    PHP_OPCACHE_INTERNED_STRINGS_BUFFER=16 \
    TRUSTED_PROXY=off
RUN install -d -m 700 -o www-data -g www-data /var/www/opcache

COPY --chmod=755 docker/production/entrypoint.d/ /etc/entrypoint.d

COPY --from=base --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=static-assets --chown=www-data:www-data /app/public/build ./public/build

COPY --chown=www-data:www-data composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data lang ./lang
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage
COPY --chown=www-data:www-data templates ./templates
COPY --chown=www-data:www-data resources/views ./resources/views
COPY --chown=www-data:www-data artisan artisan
COPY --chown=www-data:www-data openapi.yaml ./openapi.yaml
COPY --chown=www-data:www-data changelogs/ ./changelogs/

RUN composer dump-autoload

COPY docker/production/etc/nginx/conf.d/custom.conf /etc/nginx/conf.d/custom.conf
COPY docker/production/etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/http.conf

# The S6 services of the official image, registered where S6 Overlay 3.2.3.2 looks for them.
# A /etc/s6-overlay/s6-rc.d/user directory would hide the services of the base image and stop
# the container at boot.
COPY --chmod=755 docker/production/etc/s6-overlay/ /etc/s6-overlay/
RUN set -eu; \
    if [ -d /etc/s6-overlay/s6-rc.d/user/contents.d ]; then \
    mkdir -p /etc/s6-overlay/user-bundles.d/user/contents.d; \
    cp -a /etc/s6-overlay/s6-rc.d/user/contents.d/. /etc/s6-overlay/user-bundles.d/user/contents.d/; \
    rm -rf /etc/s6-overlay/s6-rc.d/user; \
    fi; \
    for service in db-migration init-seeder init-script horizon scheduler-worker; do \
    test -e "/etc/s6-overlay/user-bundles.d/user/contents.d/${service}"; \
    done

RUN mkdir -p /etc/nginx/conf.d && \
    chown -R www-data:www-data /etc/nginx && \
    chmod -R 755 /etc/nginx

COPY --from=minio-client /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc

# Release shown to the operator (the release workflow passes the tag).
ARG PLATFORM_VERSION=dev
ENV PLATFORM_VERSION=${PLATFORM_VERSION}

LABEL org.opencontainers.image.title="Hostrema" \
    org.opencontainers.image.description="Multi-tenant PaaS built on Coolify" \
    org.opencontainers.image.base.name="docker.io/serversideup/php:${SERVERSIDEUP_PHP_VERSION}"

USER www-data
