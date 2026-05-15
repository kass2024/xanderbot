#!/usr/bin/env bash
# Diagnose xanderbot.site 503 — run as root on server
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=php-env.sh
source "${SCRIPT_DIR}/php-env.sh"

APP_DIR="${1:-/var/www/xanderbot}"
echo "========== xanderbot 503 diagnostic =========="
echo "App: $APP_DIR"
echo "CLI PHP: $PHP_BIN"
"$PHP_BIN" -v | head -1
echo ""

echo "--- PHP-FPM listen (from config) ---"
php_fpm_listen_from_config 2>/dev/null || echo "(not found in config)"
echo ""

echo "--- PHP-FPM services ---"
systemctl list-units '*php*fpm*' --no-pager 2>/dev/null | head -15 || true
pgrep -af 'php-fpm' 2>/dev/null | head -5 || true
echo ""

echo "--- Sockets ---"
ls -la /opt/php82/var/run/ 2>/dev/null || true
ls -la /run/php/*.sock 2>/dev/null || echo "No /run/php sockets"
echo "detect_php_fpm_socket: $(detect_php_fpm_socket 2>/dev/null || echo MISSING)"
echo ""

echo "--- Apache vhost (SSL) ---"
SSL_CONF="/etc/apache2/sites-enabled/xanderbot.site-le-ssl.conf"
if [[ -f "$SSL_CONF" ]]; then
  grep -E 'DocumentRoot|SetHandler|ProxyPass|php|fcgi' "$SSL_CONF" || cat "$SSL_CONF"
else
  echo "MISSING: $SSL_CONF"
fi
echo ""

echo "--- Maintenance mode ---"
ls -la "$APP_DIR/storage/framework/" 2>/dev/null | grep -E 'down|maintenance' || echo "No down file"
echo ""

echo "--- artisan ---"
cd "$APP_DIR" || exit 1
"$PHP_BIN" artisan --version 2>&1 || echo "artisan FAILED"
echo ""

echo "--- HTTP test ---"
curl -sI -H 'Host: xanderbot.site' https://127.0.0.1/ -k 2>&1 | head -8 || true
curl -s -H 'Host: xanderbot.site' http://127.0.0.1/ping.php 2>&1 | head -5 || true
echo ""

echo "--- Apache errors ---"
tail -25 /var/log/apache2/error.log 2>/dev/null || true
tail -15 /var/log/apache2/xanderbot.site-ssl-error.log 2>/dev/null || true
echo "========== end =========="
