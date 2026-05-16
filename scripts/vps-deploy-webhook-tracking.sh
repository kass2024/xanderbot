#!/usr/bin/env bash
# Run on VPS as root from /var/www/xanderbot after git pull
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Pull latest (if git repo)"
git pull 2>/dev/null || echo "(skip git pull)"

echo "==> Check tracking code present"
if ! grep -q 'webhook-hits' app/Http/Controllers/Webhooks/MetaWebhookController.php 2>/dev/null; then
  echo "ERROR: MetaWebhookController missing webhook-hits logging. Upload/pull latest code first."
  exit 1
fi

echo "==> Ensure log directory"
mkdir -p storage/logs
touch storage/logs/webhook-hits.log
touch storage/logs/laravel.log
chmod -R 775 storage/logs 2>/dev/null || true

# Web server user (adjust if needed)
for u in www-data apache nginx; do
  if id "$u" &>/dev/null; then
    chown -R "$u:$u" storage/logs storage/framework 2>/dev/null || true
    break
  fi
done

echo "==> Laravel config"
php artisan config:clear
php artisan config:cache

echo "==> Test webhook POST (signature will fail — should still append webhook-hits.log)"
curl -sS -o /dev/null -w "HTTP %{http_code}\n" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"object":"whatsapp_business_account","entry":[]}' \
  https://xanderbot.site/api/webhook/meta || true

echo "==> Last webhook hits:"
tail -5 storage/logs/webhook-hits.log 2>/dev/null || echo "(empty)"

echo "==> Diagnostic URL: https://xanderbot.site/api/webhook/diagnostic"
echo "Done. tail -f storage/logs/webhook-hits.log"
