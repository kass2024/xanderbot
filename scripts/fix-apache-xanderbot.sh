#!/usr/bin/env bash
# Fix xanderbot.site 503: DocumentRoot + PHP-FPM (supports /opt/php82). Run as root.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=php-env.sh
source "${SCRIPT_DIR}/php-env.sh"

APP_DIR="/var/www/xanderbot"
WEB_USER="www-data"

PHP_SOCK="$(detect_php_fpm_socket || true)"
if [[ -z "$PHP_SOCK" ]]; then
  echo "ERROR: Could not find PHP-FPM socket for ${PHP_BIN}."
  echo "Check FPM is running and listen= in pool config:"
  echo "  grep -r '^listen' /opt/php82/etc/php-fpm* 2>/dev/null"
  echo "  ls -la /opt/php82/var/run/ /run/php/"
  echo "Start FPM: systemctl start php82-fpm  OR  /opt/php82/sbin/php-fpm"
  exit 1
fi

if [[ "$PHP_SOCK" == tcp:* ]]; then
  SET_HANDLER="$(apache_php_sethandler "$PHP_SOCK")"
  echo "Using PHP-FPM TCP: ${PHP_SOCK#tcp:}"
else
  SET_HANDLER="$(apache_php_sethandler "$PHP_SOCK")"
  echo "Using PHP-FPM socket: $PHP_SOCK"
  if [[ ! -S "$PHP_SOCK" ]]; then
    echo "WARN: Socket file missing — starting PHP-FPM..."
    restart_php_fpm
    sleep 1
  fi
fi

echo "CLI PHP: $("$PHP_BIN" -v | head -1)"

# Laravel repair
if [[ -f "$APP_DIR/scripts/server-repair.sh" ]]; then
  PHP_BIN="$PHP_BIN" bash "$APP_DIR/scripts/server-repair.sh" "$APP_DIR"
fi

cd "$APP_DIR"
"$PHP_BIN" artisan up 2>/dev/null || true
rm -f storage/framework/maintenance.php 2>/dev/null || true

write_vhost() {
  local port="$1"
  local extra="$2"
  local logbase="$3"
  cat <<EOF
<VirtualHost *:${port}>
    ServerName xanderbot.site
    ServerAlias www.xanderbot.site
    DocumentRoot ${APP_DIR}/public

    <Directory ${APP_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        ${SET_HANDLER}
    </FilesMatch>

    ${extra}

    ErrorLog \${APACHE_LOG_DIR}/${logbase}-error.log
    CustomLog \${APACHE_LOG_DIR}/${logbase}-access.log combined
</VirtualHost>
EOF
}

SSL_CERT="/etc/letsencrypt/live/xanderbot.site/fullchain.pem"
SSL_KEY="/etc/letsencrypt/live/xanderbot.site/privkey.pem"
SSL_BLOCK=""
if [[ -f "$SSL_CERT" && -f "$SSL_KEY" ]]; then
  SSL_BLOCK="SSLEngine on
    SSLCertificateFile ${SSL_CERT}
    SSLCertificateKeyFile ${SSL_KEY}
    Include /etc/letsencrypt/options-ssl-apache.conf"
fi

write_vhost 80 "" "xanderbot.site" > /etc/apache2/sites-available/xanderbot.site.conf

{
  echo '<IfModule mod_ssl.c>'
  write_vhost 443 "$SSL_BLOCK" "xanderbot.site-ssl"
  echo '</IfModule>'
} > /etc/apache2/sites-available/xanderbot.site-le-ssl.conf

a2enmod rewrite ssl proxy proxy_fcgi setenvif headers 2>/dev/null || true
a2ensite xanderbot.site.conf 2>/dev/null || true
a2ensite xanderbot.site-le-ssl.conf 2>/dev/null || true

apache2ctl configtest
restart_php_fpm
systemctl reload apache2

echo ""
echo "Test (same PHP as artisan):"
curl -sI -H 'Host: xanderbot.site' http://127.0.0.1/ | head -5
curl -s -H 'Host: xanderbot.site' http://127.0.0.1/ping.php 2>/dev/null | head -3 || true
echo "Done — https://xanderbot.site"
