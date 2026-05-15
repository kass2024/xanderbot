#!/usr/bin/env bash
# Prescreening on split hosting: admin on cPanel Xander, webhook on VPS xanderbot.
set -euo pipefail

APP_DIR="${1:-/var/www/xanderbot}"
LEGACY="${APP_DIR}/legacy/xander"
PHP_BIN="/opt/php82/bin/php"
[[ -x "$PHP_BIN" ]] || PHP_BIN="php"

echo "==> Prescreening VPS setup"
echo "    Helpers: ${LEGACY}"

if [[ ! -f "${LEGACY}/helpers/prescreening_whatsapp_flow.php" ]]; then
  echo "ERROR: Run git pull — legacy/xander helpers missing."
  exit 1
fi

mkdir -p "${LEGACY}/uploads/prescreening"
chown -R www-data:www-data "${LEGACY}/uploads" 2>/dev/null || true
chmod -R ug+rwx "${LEGACY}/uploads"

cd "$APP_DIR"

# Use bundled path unless overridden
if ! grep -q '^XANDER_PHP_PATH=' .env 2>/dev/null; then
  echo "XANDER_PHP_PATH=${LEGACY}" >> .env
  echo "Added XANDER_PHP_PATH=${LEGACY}"
else
  echo "XANDER_PHP_PATH already in .env (should be ${LEGACY})"
fi

"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan config:cache

echo ""
echo "==> DB must be the SAME as cPanel Xander (remote MySQL):"
grep -E '^DB_(HOST|DATABASE|USERNAME)=' .env || true
echo ""
echo "On Namecheap cPanel: Remote MySQL → allow VPS IP for this database."
echo ""
echo "Test DB from VPS:"
"$PHP_BIN" -r "
\$h=getenv('DB_HOST')?:'127.0.0.1';
require '${APP_DIR}/vendor/autoload.php';
\$app=require '${APP_DIR}/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
\$c=config('database.connections.mysql');
\$m=@new mysqli(\$c['host'],\$c['username'],\$c['password'],\$c['database']);
echo \$m->connect_errno ? 'FAIL: '.\$m->connect_error : 'OK connected to '.\$c['database'];
echo PHP_EOL;
" 2>/dev/null || echo "(run: php artisan tinker — DB::connection()->getPdo())"

echo ""
echo "Done. Admin invites: cPanel Xander → Pre-screening → Send"
echo "Student replies: webhook xanderbot.site → legacy helpers + shared DB"
