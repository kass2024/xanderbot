#!/usr/bin/env bash
# Fix Laravel storage/cache permissions (Resync + live Meta refresh write to framework/cache).
set -euo pipefail

APP_DIR="${1:-/var/www/xanderbot}"
WEB_USER="${2:-www-data}"

if [[ ! -d "$APP_DIR" ]]; then
  echo "App directory not found: $APP_DIR"
  exit 1
fi

cd "$APP_DIR"

echo "Fixing storage permissions in $APP_DIR (owner: $WEB_USER)…"

mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache

chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

php artisan cache:clear || true
php artisan optimize:clear || true

echo "Done. Resync from Meta should work without permission errors."
