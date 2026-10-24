# SEEDBOXAPI — production image
#
# Same layout as the original docker/ setup (Ubuntu + nginx + PHP-FPM run
# by supervisord in one container), hardened for production:
#
#   - Ubuntu 26.04 LTS with PHP 8.5 from Ubuntu's own signed repositories
#     (no third-party PPA, no Composer binary in the repo)
#   - Application code, Composer vendor and built assets are baked into the
#     image (immutable deploys); nothing is bind-mounted from the host
#   - Build tools (Composer, Node, git) exist only in the build stages
#   - Every process runs as www-data; nginx listens on 8080; code is
#     root-owned and read-only to PHP
#
# Build from the repository root (the root .dockerignore keeps .env, vendor,
# node_modules and other local files out of the build context):
#   docker compose -f docker-prod/docker-compose.yml build
#
# Stages: base → tools → vendor + assets → build → runtime. BuildKit runs
# `composer install` and `npm ci` in parallel, and each step is reused from
# cache until its own inputs change.

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
FROM base AS tools

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
    ln -sfn ../storage/app/public public/storage

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

ARG PHP_VERSION
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=single,stderr \
    QUEUE_WORKERS=1

# Configuration (same file layout as the original docker/ folder).
COPY docker-prod/etc/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker-prod/etc/nginx/conf.d/www.conf /etc/nginx/conf.d/www.conf
COPY docker-prod/etc/php-fpm.d/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY docker-prod/php/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-seedboxapi.ini
COPY docker-prod/php/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-seedboxapi.ini
COPY docker-prod/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=0755 docker-prod/entrypoint.sh /usr/local/bin/seedboxapi-entrypoint

# System tweaks that do not depend on the application code.
RUN set -eux; \
    # Run PHP-FPM unprivileged: pid and logs go to /tmp and stderr.
    sed -ri \
        -e 's#^;?\s*pid\s*=.*#pid = /tmp/php-fpm.pid#' \
        -e 's#^;?\s*error_log\s*=.*#error_log = /proc/self/fd/2#' \
        /etc/php/${PHP_VERSION}/fpm/php-fpm.conf; \
    ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/local/sbin/php-fpm; \
    php -m | grep -qi 'zend opcache'

# Application code: owned by root, so a compromised PHP process cannot change it.
COPY --from=build /app /var/www/html

RUN set -eux; \
    cd /var/www/html; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache; \
    # SQLite needs to write the database file *and* its -journal/-wal/-shm
    # siblings, so the database lives in its own writable directory (mounted
    # as a volume at runtime) rather than in the read-only code tree.
    mkdir -p database/sqlite; \
    chown -R www-data:www-data storage bootstrap/cache database/sqlite; \
    chmod -R u=rwX,g=rX,o= storage bootstrap/cache database/sqlite; \
    php -m | grep -qi 'zend opcache'

WORKDIR /var/www/html
USER www-data:www-data

EXPOSE 8080

STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/bin/tini", "--", "seedboxapi-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
