#!/bin/sh
set -eu
umask 027
case "${APP_ENV:-}" in uat|production|testing) ;; *) echo 'Explicit APP_ENV=uat, production or testing required.' >&2; exit 1 ;; esac
[ "${APP_DEBUG:-false}" = false ] || { echo 'Debug must be false.' >&2; exit 1; }
[ -n "${APP_KEY:-}" ] || { echo 'APP_KEY must be injected.' >&2; exit 1; }
mkdir -p /tmp/apache2 storage/app/document-vault storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
for directory in storage/app/document-vault storage/app/private storage/framework bootstrap/cache; do
    [ -w "$directory" ] || { echo 'Laravel writable directories unavailable.' >&2; exit 1; }
done
# Cache only after environment injection; never run migrations or create secrets here.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
exec docker-php-entrypoint "$@"
