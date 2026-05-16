#!/usr/bin/env bash
# On VPS: deploy .env.linux as .env
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ ! -f "$ROOT/.env.linux" ]]; then
  echo "Missing $ROOT/.env.linux" >&2
  exit 1
fi
cp "$ROOT/.env.linux" "$ROOT/.env"
cd "$ROOT"
php artisan config:clear
php artisan config:cache
echo "Linux production .env active (APP_ENV=production, no XANDER_PHP_PATH)."
