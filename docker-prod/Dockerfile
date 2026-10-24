# SEEDBOXAPI — production image
#
# One container (Ubuntu + nginx + PHP-FPM + Laravel scheduler under
# supervisord) built to run inside gluetun's network:
#
#   seedboxapi:
#     image: seedboxapi
#     network_mode: "service:gluetun"
#     volumes:
#       - ./seedboxapi-volume/config:/config
#       - ./seedboxapi-volume/tmp:/tmp
#     environment:
#       - PUID=1000
#       - PGID=1000
#       - DEBUG=0
#       - mam_id=
#       - INTERVAL=600
#
#   - Ubuntu 26.04 LTS with PHP 8.5 from Ubuntu's own repositories
#   - Code, Composer vendor and built assets are baked in, root-owned and
#     read-only to PHP; build tools exist only in the build stages
#   - The entrypoint starts as root only to apply PUID/PGID and fix volume
#     ownership, then drops to www-data for every process
#   - Everything persistent (SQLite database, APP_KEY, logs) lives in /config
#
# Build from the repository root:
#   docker compose -f docker-prod/docker-compose.yml build
#
# Stages: base → tools → vendor + assets → build → runtime.

ARG UBUNTU_VERSION=26.04
ARG PHP_VERSION=8.5

# ---------------------------------------------------------------------------
# base: runtime packages shared by every stage (rarely changes)
# ---------------------------------------------------------------------------
FROM ubuntu:${UBUNTU_VERSION} AS base

ARG PHP_VERSION
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    PHP_VERSION=${PHP_VERSION}

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        tzdata \
        tini \
        nginx \
        supervisor \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-redis \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-sqlite \
        php${PHP_VERSION}-zip; \
    # Drop distro defaults we replace or do not use.
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default; \
    rm -f /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf; \
    # The Ubuntu image ships a "ubuntu" user we do not need.
    userdel --remove ubuntu 2>/dev/null || true; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /var/cache/apt/* /var/log/apt /var/log/dpkg.log

# ---------------------------------------------------------------------------
# tools: build-only toolchain (rarely changes)
# ---------------------------------------------------------------------------
# Runs on the build machine's own platform even for an arm64 image: vendor/
# and the Vite build are platform-independent, so only the runtime stage
# needs emulation in multi-arch builds.
FROM --platform=$BUILDPLATFORM base AS tools

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends composer git unzip nodejs npm; \
    rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_HOME=/tmp/composer \
    npm_config_update_notifier=false \
    npm_config_fund=false \
    npm_config_audit=false

WORKDIR /app

# ---------------------------------------------------------------------------
# vendor: production Composer dependencies (re-runs only when composer.* change)
# ---------------------------------------------------------------------------
FROM tools AS vendor

# composer.lock* / package-lock.json*: the globs keep the build working before
# the lock files are committed. Commit them for reproducible images.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# ---------------------------------------------------------------------------
# assets: Vite build (re-runs only when the frontend sources change)
# ---------------------------------------------------------------------------
FROM tools AS assets

# Not secret: the app name compiled into the page titles. Vite used to read
# it from a copied .env; .env is no longer part of the build context.
ARG VITE_APP_NAME=SeedboxAPI
ENV VITE_APP_NAME=${VITE_APP_NAME}

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# app.css reads Laravel's pagination views from vendor/. .gitignore stops
# Tailwind from scanning the rest of vendor/ for class names.
COPY --from=vendor /app/vendor ./vendor
COPY .gitignore vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# build: assemble the application tree
# ---------------------------------------------------------------------------
FROM tools AS build

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN set -eux; \
    # Also runs `artisan package:discover` (composer.json post-autoload-dump).
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    # Keep only what the runtime needs. docker-prod/ has to stay in the build
    # context for the runtime stage's config COPYs, so it is stripped here;
    # everything else the
    # runtime does not need is already kept out by Dockerfile.dockerignore.
    rm -rf node_modules docker-prod \
        storage/logs/* bootstrap/cache/*.php; \
    ln -sfn ../storage/app/public public/storage; \
    # Host files may be 0600 (COPY keeps the mode); the code must be readable
    # by www-data at runtime while staying root-owned.
    chmod -R u=rwX,go=rX /app

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

ARG PHP_VERSION

# Defaults for running without an .env file. Everything app-specific can be
# overridden in the compose `environment:` block.
ENV APP_NAME="Seedbox API" \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr,daily \
    LOG_PATH=/config/logs/seedboxapi.log \
    LOG_DAILY_DAYS=14 \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/config/seedboxapi.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    PUID=1000 \
    PGID=1000 \
    DEBUG=0 \
    INTERVAL=600 \
    PORT=8095

# Configuration (same file layout as the original docker/ folder).
COPY --chmod=0644 docker-prod/etc/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0644 docker-prod/etc/nginx/conf.d/www.conf /etc/nginx/conf.d/www.conf
COPY --chmod=0644 docker-prod/etc/php-fpm.d/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY --chmod=0644 docker-prod/php/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-seedboxapi.ini
COPY --chmod=0644 docker-prod/php/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-seedboxapi.ini
COPY --chmod=0644 docker-prod/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=0755 docker-prod/entrypoint.sh /usr/local/bin/seedboxapi-entrypoint

# System tweaks that do not depend on the application code.
RUN set -eux; \
    # Run PHP-FPM unprivileged: pid and logs go to /tmp and stderr.
    sed -ri \
        -e 's#^;?\s*pid\s*=.*#pid = /tmp/php-fpm.pid#' \
        -e 's#^;?\s*error_log\s*=.*#error_log = /proc/self/fd/2#' \
        /etc/php/${PHP_VERSION}/fpm/php-fpm.conf; \
    ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/local/sbin/php-fpm; \
    php -m | grep -qi 'zend opcache'; \
    # setpriv (util-linux) drops root in the entrypoint.
    command -v setpriv

# Application code: owned by root, so a compromised PHP process cannot change it.
COPY --from=build /app /var/www/html

RUN set -eux; \
    cd /var/www/html; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache /config; \
    # Ownership is set again at start, after PUID/PGID are applied.
    chown -R www-data:www-data storage bootstrap/cache /config; \
    chmod -R u=rwX,g=rX,o= storage bootstrap/cache

WORKDIR /var/www/html

# Starts as root: the entrypoint applies PUID/PGID, then runs everything as www-data.
EXPOSE 8095

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".(getenv("PORT") ?: 8095)."/up") === false ? 1 : 0);'

STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/bin/tini", "--", "seedboxapi-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
