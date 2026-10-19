#!/bin/sh
# Make Apache listen on the port the platform gives us (Railway: $PORT), then start the server.
set -e
PORT="${PORT:-8080}"
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
# Exactly one Apache MPM may be loaded: keep prefork (required by mod_php), drop any other that got enabled.
for m in /etc/apache2/mods-enabled/mpm_*.load; do
  case "$m" in *mpm_prefork*) ;; *) rm -f "$m" "${m%.load}.conf" ;; esac
done
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork >/dev/null 2>&1 || true
mkdir -p /var/www/html/storage/backups /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
if [ -z "$DATABASE_URL" ] && [ -z "$SUPABASE_DB_URL" ] && [ -z "$POSTGRES_URL" ] && [ -z "$DB_HOST" ]; then
  echo "[mark6] WARNING: no DATABASE_URL / DB_HOST set — the installer will ask for the database details." >&2
fi
exec "$@"
