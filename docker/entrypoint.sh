#!/bin/sh
# Prepares the shared data volume, then runs the role's command.
set -e

cd /app

# One APP_KEY for all containers, stored on the shared volume.
if [ -z "$APP_KEY" ]; then
    if [ ! -s /data/app_key ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > /data/app_key
    fi
    APP_KEY="$(cat /data/app_key)"
    export APP_KEY
fi

# Only the web container migrates and seeds (TIMETUNE_ROLE=web).
if [ "${TIMETUNE_ROLE:-web}" = "web" ]; then
    rm -f /data/.ready
    touch /data/database.sqlite
    php artisan migrate --force --no-interaction
    if [ "$(php artisan tinker --execute 'echo App\Models\Game::count();' 2>/dev/null | tail -n1)" = "0" ]; then
        php artisan db:seed --force --no-interaction
    fi
    php artisan optimize
    touch /data/.ready
else
    # Others wait until the database has been migrated.
    until [ -f /data/.ready ]; do sleep 1; done
    php artisan optimize
fi

exec "$@"
