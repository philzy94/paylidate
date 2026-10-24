#!/bin/sh
set -e

cd /var/www/html

# Wait for the database, migrate and run first-time setup so `docker compose up` is enough.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    until php artisan migrate --force; do
        echo "Waiting for the database..."
        sleep 3
    done
    php artisan paylidate:install
fi

chown -R www-data:www-data storage bootstrap/cache
chmod 640 storage/oauth-private.key 2>/dev/null || true

exec apache2-foreground
