#!/usr/bin/env bash
# Quick webhook / bot diagnostics on VPS
set -uo pipefail

APP_DIR="${1:-/var/www/xanderbot}"
PHP_BIN="/opt/php82/bin/php"
[[ -x "$PHP_BIN" ]] || PHP_BIN="php"

cd "$APP_DIR" || exit 1

echo "=== Webhook diagnostic ==="
curl -sS "https://xanderbot.site/api/webhook/diagnostic" | "$PHP_BIN" -r 'echo json_encode(json_decode(stream_get_contents(STDIN)), JSON_PRETTY_PRINT);' 2>/dev/null || \
  curl -sS "https://xanderbot.site/api/webhook/diagnostic"
echo ""

echo "=== Recent webhook logs ==="
grep -E 'Webhook:|Platform not found|Invalid Meta|Prescreening|chatbot' storage/logs/laravel.log 2>/dev/null | tail -25 || echo "(no matches)"

echo ""
echo "=== PHP-FPM ==="
systemctl is-active php82-fpm 2>/dev/null || pgrep -af php-fpm | head -3

echo ""
echo "If platform_linked_in_db is false, connect WhatsApp in xanderbot admin."
echo "If no Webhook: inbound lines when you message, Meta may not be POSTing or signature fails."
