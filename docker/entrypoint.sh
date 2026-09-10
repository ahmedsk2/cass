#!/bin/sh
set -eu
cd /var/www/html

: "${APP_KEY:?APP_KEY must be set}"
: "${DB_HOST:?DB_HOST must be set}"

echo "[entrypoint] waiting for database ${DB_HOST}:${DB_PORT:-3306}"
i=0
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
  i=$((i+1)); [ "$i" -gt 60 ] && { echo "[entrypoint] database not reachable"; exit 1; }
  sleep 2
done

echo "[entrypoint] building caches"
su-exec app php artisan config:cache
su-exec app php artisan route:cache
su-exec app php artisan view:cache
su-exec app php artisan event:cache
su-exec app php artisan storage:link --force

echo "[entrypoint] pending migrations (not applied at boot):"
su-exec app php artisan migrate:status 2>/dev/null | grep -c Pending || true

exec supervisord -c /etc/supervisord.conf
