#!/usr/bin/env sh
set -eu

export PORT="${PORT:-10000}"

envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

mkdir -p \
    /run/nginx \
    /var/lib/nginx/tmp/client_body \
    /var/log/nginx \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/private/ai-learning/candidate-state \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod 700 storage/app/private/ai-learning storage/app/private/ai-learning/candidate-state

if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    SQLITE_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    SQLITE_DIR="$(dirname "$SQLITE_PATH")"

    mkdir -p "$SQLITE_DIR"
    touch "$SQLITE_PATH"
    chown -R www-data:www-data "$SQLITE_DIR"
fi

php artisan config:clear
php artisan storage:link || true
php artisan migrate --force

if [ "${RUN_DEMO_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache

php-fpm -D
php artisan reverb:start --host=127.0.0.1 --port=8080 &

# Dokploy enables private feedback exports; the free Render demo leaves this off.
# Candidate training is a separate Python worker, not part of the PHP image.
if [ "${RUN_SCHEDULER:-false}" = "true" ]; then
    su-exec www-data:www-data php artisan schedule:work &
fi

exec nginx -g 'daemon off;'
