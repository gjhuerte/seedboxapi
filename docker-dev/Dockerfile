# SEEDBOXAPI — development image
#
# Same layout as the original docker/ setup: Ubuntu + nginx + PHP-FPM run by
# supervisord in one container, with the project bind-mounted from the host.
# Updated to Ubuntu 26.04 LTS / PHP 8.5 (Ubuntu's own packages, no PPA) and
# extended with debugging tools:
#
#   PHP:     Xdebug (off by default), PCOV for fast coverage, Composer
#   Ops:     supervisorctl, PHP-FPM status page (/fpm-status), slow-request log
#   Shell:   git, curl, jq, vim, nano, less, htop, strace, ping, dig, ss
#   Data:    mariadb-client, redis-tools, sqlite3
#
# Everything runs as www-data remapped to your host UID/GID, so files the
# container writes into the project stay owned by you.
#
# Build context is this folder (no project files are copied in).

ARG UBUNTU_VERSION=26.04

FROM ubuntu:${UBUNTU_VERSION}

ARG PHP_VERSION=8.5
ARG UID=1000
ARG GID=1000

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    PHP_VERSION=${PHP_VERSION} \
    COMPOSER_HOME=/home/www-data/.composer \
    XDEBUG_MODE=off

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates tzdata tini \
        nginx supervisor \
        php${PHP_VERSION}-fpm php${PHP_VERSION}-cli \
        php${PHP_VERSION}-bcmath php${PHP_VERSION}-curl php${PHP_VERSION}-gd \
        php${PHP_VERSION}-intl php${PHP_VERSION}-mbstring php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-redis php${PHP_VERSION}-sqlite3 php${PHP_VERSION}-xml \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-xdebug php${PHP_VERSION}-pcov \
        composer \
        git unzip curl jq less vim-tiny nano htop procps strace \
        iproute2 iputils-ping dnsutils \
        mariadb-client redis-tools sqlite3; \
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default; \
    rm -f /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Remap www-data to the host user. Ubuntu ships a "ubuntu" user with UID 1000.
RUN set -eux; \
    userdel --remove ubuntu 2>/dev/null || true; \
    groupmod -o -g "${GID}" www-data; \
    usermod -o -u "${UID}" -g www-data -d /home/www-data -s /bin/bash www-data; \
    mkdir -p /home/www-data/.composer /var/www/html; \
    chown -R www-data:www-data /home/www-data /var/www/html

# Configuration (same file layout as the original docker/ folder).
COPY etc/nginx/nginx.conf /etc/nginx/nginx.conf
COPY etc/nginx/conf.d/www.conf /etc/nginx/conf.d/www.conf
COPY etc/php-fpm.d/www.conf /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY php/php.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-seedboxapi.ini
COPY php/php.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-seedboxapi.ini
COPY supervisord.conf /etc/supervisor/supervisord.conf

RUN set -eux; \
    sed -ri \
        -e 's#^;?\s*pid\s*=.*#pid = /tmp/php-fpm.pid#' \
        -e 's#^;?\s*error_log\s*=.*#error_log = /proc/self/fd/2#' \
        /etc/php/${PHP_VERSION}/fpm/php-fpm.conf; \
    ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/local/sbin/php-fpm

WORKDIR /var/www/html
USER www-data:www-data

EXPOSE 8080 5173

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
