#!/usr/bin/env bash
# Run on the Linux server as root from /var/www/xanderbot
#   chmod +x scripts/server-repair.sh && ./scripts/server-repair.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=php-env.sh
source "${SCRIPT_DIR}/php-env.sh"

APP_DIR="${1:-/var/www/xanderbot}"
WEB_USER="${WEB_USER:-www-data}"

echo "==> Repairing Laravel app at ${APP_DIR} (PHP: ${PHP_BIN})"
cd "$APP_DIR"

if [[ ! -f artisan ]]; then
  echo "ERROR: artisan not found. Set path: ./scripts/server-repair.sh /var/www/xanderbot"
  exit 1
fi

# 1) Take site out of maintenance mode
if [[ -f storage/framework/maintenance.php ]] || [[ -f storage/framework/down ]]; then
  echo "==> Disabling maintenance mode"
  "$PHP_BIN" artisan up || true
  rm -f storage/framework/maintenance.php 2>/dev/null || true
fi

# 2) Writable directories (sessions, cache, logs, uploads)
echo "==> Fixing storage & bootstrap/cache permissions"
mkdir -p storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache
mkdir -p storage/app/public storage/app/public/whatsapp/inbound
chmod -R ug+rwx storage bootstrap/cache
chown -R "${WEB_USER}:${WEB_USER}" storage bootstrap/cache 2>/dev/null || true

# 3) Public storage link (voice notes, uploads)
if [[ ! -L public/storage ]]; then
  echo "==> Creating storage link"
  "$PHP_BIN" artisan storage:link || true
fi

# 4) Clear stale caches
echo "==> Clearing caches"
"$PHP_BIN" artisan optimize:clear || true

# 5) Rebuild caches as web user if possible
echo "==> Rebuilding config & routes"
if id "$WEB_USER" &>/dev/null; then
  sudo -u "$WEB_USER" "$PHP_BIN" artisan config:cache || "$PHP_BIN" artisan config:cache || true
  sudo -u "$WEB_USER" "$PHP_BIN" artisan route:cache || "$PHP_BIN" artisan route:cache || true
else
  "$PHP_BIN" artisan config:cache || true
  "$PHP_BIN" artisan route:cache || true
fi

# 6) Prescreening bridge path (legacy Xander PHP on same server)
if grep -q '^XANDER_PHP_PATH=' .env 2>/dev/null; then
  echo "XANDER_PHP_PATH already set in .env"
else
  for CAND in /var/www/html/Xander /var/www/Xander /var/www/html; do
    if [[ -f "${CAND}/helpers/prescreening_whatsapp_flow.php" ]]; then
      echo "==> Setting XANDER_PHP_PATH=${CAND}"
      echo "XANDER_PHP_PATH=${CAND}" >> .env
      break
    fi
  done
fi

FPM_SOCK="$(detect_php_fpm_socket || echo 'not found')"
echo ""
echo "==> Done. PHP-FPM for Apache: ${FPM_SOCK}"
echo "    If site is 503, run: ./scripts/fix-apache-xanderbot.sh"
echo "    curl -sI https://xanderbot.site | head -5"
echo "    curl -s https://xanderbot.site/api/health"
echo ""
echo "Apache DocumentRoot MUST be: ${APP_DIR}/public"
