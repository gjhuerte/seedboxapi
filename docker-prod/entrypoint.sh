#!/bin/sh
#
# SEEDBOXAPI production entrypoint.
#
# 1. Refuses to start without APP_KEY.
# 1b. Creates the SQLite database file when DB_CONNECTION=sqlite.
# 2. Warms Laravel's config, route, view and event caches.
# 3. Runs migrations when RUN_MIGRATIONS=true.
# 4. Runs the database seeder when RUN_SEEDER=true.
# 5. Hands over to supervisord (nginx, PHP-FPM, queue worker, scheduler).
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "[seedboxapi] APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

cd /var/www/html

# SQLite will not create the database file itself; `migrate` fails with
# "unable to open database file" when it is missing.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    SQLITE_DB="${DB_DATABASE:-/var/www/html/database/sqlite/database.sqlite}"
    if [ ! -f "$SQLITE_DB" ]; then
        if ! touch "$SQLITE_DB" 2>/dev/null; then
            echo "[seedboxapi] cannot create $SQLITE_DB — its directory must be a writable volume owned by www-data (uid 33)." >&2
            exit 1
        fi
        chmod 0640 "$SQLITE_DB"
        echo "[seedboxapi] created empty SQLite database at $SQLITE_DB"
    fi
fi

php artisan optimize --no-ansi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-ansi
fi

# Runs DatabaseSeeder. The image is built with --no-dev, so seeders must not
# use factories/fake(). Keep seeders idempotent if you leave this on, and pair
# it with RUN_MIGRATIONS=true on a new database.
if [ "${RUN_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force --no-ansi
fi

exec "$@"
