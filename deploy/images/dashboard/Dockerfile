# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e
#
# launchroom dashboard image (Laravel on FrankenPHP, classic mode).
#
# Build context is the REPO ROOT:
#   docker build -f deploy/images/dashboard/Dockerfile --target production -t ghcr.io/vantezzen/launchroom-dashboard .
#   docker build -f deploy/images/dashboard/Dockerfile --target dev .      # used by dev/compose.yml
#
# Targets:
#   dev         No app code baked in (dev/compose.yml bind-mounts dashboard/).
#               Runs as a non-root user whose UID/GID match the host (build args),
#               ships composer and Node so `vite` (and its wayfinder plugin, which
#               calls `php artisan`) can run in the same image.
#   production  Code, vendor (no dev deps) and built assets baked in. The same
#               image runs web, horizon, reverb and scheduler (see entrypoint.sh).
#
# Multi-arch (`docker buildx build --platform linux/amd64,linux/arm64`): the
# platform-independent work (composer install, the Vite build) runs once, on
# the BUILD platform (stages build-php, vendor, assets), and is copied into
# each target image. Only the per-platform stages (base, dev, production)
# run under emulation for a foreign platform: OS packages, PHP extensions,
# users and COPYs. build-php has the same FrankenPHP base and extensions as
# base, so composer resolves and checks platform requirements exactly as on
# the target (no --ignore-platform-reqs).

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4-trixie@sha256:b32a81ad4db40b528ada04087f47e22833f260266e73b3d88c62fd54e2a29aa6
ARG COMPOSER_IMAGE=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
ARG NODE_IMAGE=node:24-trixie-slim@sha256:7f486cabe892bff8fef04c0f7da8dba84a560be3e4e2740b7a86afc57ebec315

# PHP extensions on top of the FrankenPHP defaults (sodium ships with the base
# image; install-php-extensions skips what exists). One list for base and
# build-php, so the native build stages see the same platform as the image.
ARG PHP_EXTENSIONS="pdo_pgsql pgsql redis sodium intl pcntl zip opcache bcmath"

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node
FROM --platform=$BUILDPLATFORM ${NODE_IMAGE} AS node-build

# ---------------------------------------------------------------------------
# base: FrankenPHP + PHP extensions + Caddyfile. Shared by every target.
# ---------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

ARG PHP_EXTENSIONS
RUN install-php-extensions $PHP_EXTENSIONS

# Backups (docs/14 §2): the control-plane backup and `launchroom:restore` run
# in this image. restic is a pinned release binary, verified by SHA-256 per
# architecture; the Postgres 18 client tools (pg_dump/pg_restore must match
# the server's major version; trixie ships 17) come from the PGDG apt
# repository, whose signing key is pinned by fingerprint (apt then verifies
# every package against the signed repository metadata).
ARG RESTIC_VERSION=0.19.1
ARG RESTIC_SHA256_AMD64=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c
ARG RESTIC_SHA256_ARM64=a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465
ARG PG_CLIENT_MAJOR=18
ARG PGDG_KEY_FINGERPRINT=B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
        amd64) restic_sha="$RESTIC_SHA256_AMD64" ;; \
        arm64) restic_sha="$RESTIC_SHA256_ARM64" ;; \
        *) echo "unsupported architecture $arch" >&2; exit 1 ;; \
    esac; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg bzip2; \
    curl -fsSL -o /tmp/restic.bz2 "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_${arch}.bz2"; \
    echo "${restic_sha}  /tmp/restic.bz2" | sha256sum -c -; \
    bunzip2 -c /tmp/restic.bz2 > /usr/local/bin/restic; \
    chmod 0755 /usr/local/bin/restic; \
    curl -fsSL -o /tmp/pgdg.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    gpg --batch --with-colons --show-keys /tmp/pgdg.asc | grep -q "^fpr:::::::::${PGDG_KEY_FINGERPRINT}:\$"; \
    gpg --batch --dearmor -o /usr/share/keyrings/pgdg.gpg /tmp/pgdg.asc; \
    . /etc/os-release; \
    echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends "postgresql-client-${PG_CLIENT_MAJOR}"; \
    apt-get purge -y --auto-remove gnupg; \
    rm -rf /var/lib/apt/lists/* /tmp/restic.bz2 /tmp/pgdg.asc; \
    restic version; \
    "/usr/lib/postgresql/${PG_CLIENT_MAJOR}/bin/pg_dump" --version

COPY deploy/images/dashboard/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/images/dashboard/php.ini "$PHP_INI_DIR/conf.d/zz-launchroom.ini"
COPY deploy/images/dashboard/wait-for-db.php /usr/local/lib/launchroom/wait-for-db.php

ENV SERVER_NAME=:8000 \
    SERVER_ROOT=/var/www/html/public

WORKDIR /var/www/html
EXPOSE 8000

# Laravel's built-in health route. Worker containers (horizon/reverb/scheduler)
# should override or disable this healthcheck in their compose service.
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:8000/up || exit 1

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
# dev: tooling, host-matching user, no app code.
# ---------------------------------------------------------------------------
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Create a user matching the host UID/GID so files written into the bind-mounted
# dashboard/ stay owned by the developer on Linux hosts. On macOS the GID is
# often 20 (staff), which already exists in Debian (dialout): reuse it then.
RUN set -eux; \
    if ! getent group "${GID}" >/dev/null; then groupadd --gid "${GID}" launchroom; fi; \
    if getent passwd "${UID}" >/dev/null; then userdel --remove "$(getent passwd "${UID}" | cut -d: -f1)"; fi; \
    useradd --uid "${UID}" --gid "${GID}" --create-home --home-dir /home/launchroom --shell /bin/bash launchroom; \
    # Pre-create mount points so named volumes inherit this ownership on first use.
    mkdir -p /data/caddy /config/caddy /data/keys /home/launchroom/.composer/cache /var/www/html/node_modules; \
    chmod 0700 /data/keys; \
    chown -R "${UID}:${GID}" /data /config /home/launchroom /var/www/html

ENV COMPOSER_HOME=/home/launchroom/.composer \
    COMPOSER_CACHE_DIR=/home/launchroom/.composer/cache

USER launchroom

# ---------------------------------------------------------------------------
# build-php: base's PHP (same image, extensions and php.ini overrides) on the
# BUILD platform, for the platform-independent build stages below.
# ---------------------------------------------------------------------------
FROM --platform=$BUILDPLATFORM ${FRANKENPHP_IMAGE} AS build-php

ARG PHP_EXTENSIONS
RUN install-php-extensions $PHP_EXTENSIONS
COPY deploy/images/dashboard/php.ini "$PHP_INI_DIR/conf.d/zz-launchroom.ini"
WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: production composer dependencies + optimized autoloader (pure PHP,
# platform-independent; built natively).
# ---------------------------------------------------------------------------
FROM build-php AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY dashboard/composer.json dashboard/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY dashboard/ ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/app/public storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------
# assets: Vite build (platform-independent JS/CSS; built natively). Needs PHP
# too: the wayfinder Vite plugin runs artisan.
# ---------------------------------------------------------------------------
FROM vendor AS assets

COPY --from=node-build /usr/local/bin/node /usr/local/bin/node
COPY --from=node-build /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund \
    && npm run build

# ---------------------------------------------------------------------------
# production
# ---------------------------------------------------------------------------
FROM base AS production

ARG UID=1000
ARG GID=1000

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    # Code is immutable in this image: never stat files for changes.
    && printf 'opcache.validate_timestamps = 0\n' > "$PHP_INI_DIR/conf.d/zz-launchroom-production.ini"

RUN groupadd --gid "${GID}" launchroom \
    && useradd --uid "${UID}" --gid "${GID}" --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin launchroom \
    && mkdir -p /data/caddy /config/caddy /data/keys \
    && chmod 0700 /data/keys \
    && chown -R launchroom:launchroom /data /config

COPY --from=vendor --chown=root:root /var/www/html /var/www/html
COPY --from=assets --chown=root:root /var/www/html/public/build /var/www/html/public/build
COPY --chmod=0755 deploy/images/dashboard/entrypoint.sh /usr/local/bin/launchroom-entrypoint
# Served by the dashboard at /install/server.sh (templated with this instance's
# URL, signing key and launchroomd image; launchroom.install.scripts_path).
COPY --chown=root:root deploy/install/server.sh /var/www/install/server.sh
COPY --chown=root:root deploy/daemon/compose.yml /var/www/install/daemon-compose.yml
# Protocol schemas: every rendered NodeState is validated against them before
# it is published (launchroom.protocol.schema_path, NodeStateValidator).
COPY --chown=root:root protocol/schema/v1 /var/www/protocol/schema/v1

# Code is root-owned and read-only for the runtime user; only storage/ and
# bootstrap/cache are writable.
RUN chown -R launchroom:launchroom storage bootstrap/cache

# Release version (root VERSION file; the release workflow passes it). The
# dashboard reads it as config('launchroom.version'). Late in the stage so a
# version bump does not invalidate the cached layers above.
ARG VERSION=0.0.0-dev
ENV LAUNCHROOM_VERSION=${VERSION}
LABEL org.opencontainers.image.title="launchroom-dashboard" \
      org.opencontainers.image.description="launchroom control plane (Laravel on FrankenPHP)" \
      org.opencontainers.image.source="https://github.com/vantezzen/launchroom" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${VERSION}"

USER launchroom

ENTRYPOINT ["launchroom-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
