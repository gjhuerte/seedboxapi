#!/bin/sh
#
# SEEDBOXAPI production entrypoint. Starts as root, like the official
# myanonamouse/seedboxapi and linuxserver.io images, only to:
#
#   1. map www-data to PUID/PGID so files in /config belong to you,
#   2. make /config and /tmp usable (both may be bind mounts),
#   3. listen on PORT (every app in gluetun's network shares its ports),
#   4. create or load APP_KEY (/config/app.key) and the SQLite database,
#   5. cache config and run migrations,
#
# then starts supervisord. supervisord and the nginx / PHP-FPM masters stay
# root only to open the log pipes and the socket; every worker and the
# scheduler run as www-data.
set -eu

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
PORT="${PORT:-8095}"
APP_DIR=/var/www/html

log() { echo "[seedboxapi] $*"; }

if [ "$(id -u)" != "0" ]; then
    log "must start as root to apply PUID/PGID; remove any 'user:' from the compose service." >&2
    exit 1
fi

# 1. User mapping --------------------------------------------------------
[ "$(id -g www-data)" = "$PGID" ] || groupmod -o -g "$PGID" www-data
[ "$(id -u www-data)" = "$PUID" ] || usermod -o -u "$PUID" www-data

# 2. Writable paths ------------------------------------------------------
mkdir -p /config/logs
chown www-data:www-data /config /config/logs
chmod 1777 /tmp
# Stale sockets from a previous run in a persistent /tmp.
rm -f /tmp/php-fpm.sock /tmp/supervisor.sock /tmp/supervisord.pid /tmp/nginx.pid
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# 3. Port ----------------------------------------------------------------
case "$PORT" in
    ''|*[!0-9]*) log "PORT must be a number, got '$PORT'." >&2; exit 1 ;;
esac
sed -ri "s/listen [0-9]+ default_server;/listen ${PORT} default_server;/" /etc/nginx/conf.d/www.conf

# 4. APP_KEY, logging, database ------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s /config/app.key ]; then
        umask 077
        printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64)" > /config/app.key
        umask 022
        log "generated APP_KEY in /config/app.key (keep it: it decrypts the stored mam_id)"
    fi
    APP_KEY="$(cat /config/app.key)"
    export APP_KEY
fi
[ ! -f /config/app.key ] || { chown www-data:www-data /config/app.key; chmod 600 /config/app.key; }

case "${DEBUG:-0}" in
    1|true|yes|on) export LOG_LEVEL=debug ;;
    *) export LOG_LEVEL="${LOG_LEVEL:-info}" ;;
esac

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/config/seedboxapi.sqlite}"
    if [ ! -f "$DB_FILE" ]; then
        touch "$DB_FILE"
        log "created SQLite database at $DB_FILE"
    fi
    chown www-data:www-data "$DB_FILE"
    chmod 0640 "$DB_FILE"
fi

# 5. App setup as www-data, then supervisord ------------------------------
as_app() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }
export HOME=/tmp

cd "$APP_DIR"
as_app php artisan optimize --no-ansi
as_app php artisan migrate --force --no-ansi

log "listening on port $PORT (PUID=$PUID PGID=$PGID INTERVAL=${INTERVAL:-600}s DEBUG=${DEBUG:-0})"

# Not dropped here on purpose: a non-root process cannot reopen Docker's
# stdout/stderr (/dev/stdout -> EACCES), so supervisord keeps root to open them
# and starts each program with its own user (see supervisord.conf).
exec "$@"
