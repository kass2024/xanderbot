#!/usr/bin/env bash
# Raise PHP upload limits to 5M for xanderbot creative images.
# Run on server: sudo bash deploy/php/install-upload-limits.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SNIPPET="$ROOT/deploy/php/99-xanderbot-upload.ini"

if [[ ! -f "$SNIPPET" ]]; then
  echo "Missing $SNIPPET"
  exit 1
fi

echo "=== PHP version ==="
php -v | head -1

echo ""
echo "=== php --ini ==="
php --ini

echo ""
echo "=== SAPI / conf.d directories under /etc/php ==="
mapfile -t CONF_DIRS < <(find /etc/php -type d -name conf.d 2>/dev/null | sort -u)

if [[ ${#CONF_DIRS[@]} -eq 0 ]]; then
  echo "No conf.d directories found. Install PHP-FPM or Apache PHP module, or edit php.ini manually."
  exit 1
fi

for dir in "${CONF_DIRS[@]}"; do
  dest="$dir/99-xanderbot-upload.ini"
  echo "Installing -> $dest"
  cp "$SNIPPET" "$dest"
done

echo ""
echo "=== Reload services (ignore failures for missing units) ==="
for svc in php8.2-fpm php-fpm8.2 php8.2-fpm.service php-fpm php8.2-fpm apache2 nginx; do
  if systemctl is-active --quiet "$svc" 2>/dev/null || systemctl list-unit-files "$svc" 2>/dev/null | grep -q enabled; then
    echo "Reloading $svc"
    systemctl reload "$svc" 2>/dev/null || systemctl restart "$svc" 2>/dev/null || true
  fi
done

echo ""
echo "=== Verify (CLI) ==="
php -i | grep -E "upload_max_filesize|post_max_size"

echo ""
echo "If the site still shows 2M, your web SAPI may differ from CLI."
echo "Check FPM pool php.ini or Apache module:"
echo "  find /etc/php -name php.ini"
echo "  public/.user.ini is also deployed for CGI/FPM per-dir ini (5 min cache)."
