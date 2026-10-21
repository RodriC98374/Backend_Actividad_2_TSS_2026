#!/bin/sh
set -eu
export PORT="${PORT:-10000}"
export APP_URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://localhost:10000}}"
export FRONTEND_URL="$APP_URL"
export SANCTUM_STATEFUL_DOMAINS="$(php -r 'echo parse_url(getenv("APP_URL"), PHP_URL_HOST).(parse_url(getenv("APP_URL"), PHP_URL_PORT) ? ":".parse_url(getenv("APP_URL"), PHP_URL_PORT) : "");')"
if [ -z "${APP_KEY:-}" ]; then
    : "${APP_SECRET:?Set APP_SECRET to a persistent random secret}"
    export APP_KEY="$(php -r 'echo "base64:".base64_encode(hash("sha256", getenv("APP_SECRET"), true));')"
fi
php artisan config:clear
php artisan migrate --force
php artisan app:bootstrap-demo
unset BOOTSTRAP_ADMIN_PASSWORD BOOTSTRAP_ADMIN_EMAIL
php artisan config:cache
php artisan route:cache
chown -R www-data:www-data storage bootstrap/cache
envsubst '${PORT}' < /etc/nginx/acredi.conf.template > /etc/nginx/sites-enabled/default
nginx -t
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/acredi.conf
