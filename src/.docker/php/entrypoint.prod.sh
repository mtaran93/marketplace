#!/bin/sh
# Production entrypoint. Caches config from the RUNTIME environment (never baked
# into the image), then hands off to the command (php-fpm by default).
# Migrations are deliberately NOT run here: with several replicas they would race.
# Run them as an explicit one-off: `... run --rm app php artisan migrate --force`.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "entrypoint: APP_KEY is empty; refusing to start" >&2
    exit 1
fi

php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan view:cache --no-ansi -q

exec "$@"
