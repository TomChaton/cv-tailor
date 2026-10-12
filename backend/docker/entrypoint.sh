#!/bin/sh
set -e

# SQLite needs the file to exist before Laravel will open it, and the database
# lives on a named volume that starts empty.
if [ -n "${DB_DATABASE}" ] && [ ! -f "${DB_DATABASE}" ]; then
    mkdir -p "$(dirname "${DB_DATABASE}")"
    touch "${DB_DATABASE}"
fi

# Only one role migrates, so three containers starting together cannot race.
if [ "${CV_RUN_MIGRATIONS}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec docker-php-entrypoint "$@"
