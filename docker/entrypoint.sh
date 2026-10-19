#!/bin/sh
# Make Apache listen on the port the platform gives us (Railway: $PORT), then start the server.
set -e
PORT="${PORT:-8080}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/sites-available/000-default.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
mkdir -p /var/www/html/storage/backups /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
if [ -z "$DATABASE_URL" ] && [ -z "$SUPABASE_DB_URL" ] && [ -z "$POSTGRES_URL" ] && [ -z "$DB_HOST" ]; then
  echo "[mark6] WARNING: no DATABASE_URL / DB_HOST set — the installer will ask for the database details." >&2
fi
exec "$@"
