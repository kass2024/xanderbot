#!/usr/bin/env bash
# Start /opt/php82 PHP-FPM (socket: /run/php/php82-fpm.sock). Run as root.
set -euo pipefail

SOCK="/run/php/php82-fpm.sock"
FPM="/opt/php82/sbin/php-fpm"
CONF="/opt/php82/etc/php-fpm.conf"

mkdir -p /run/php
chown www-data:www-data /run/php 2>/dev/null || true

if [[ ! -x "$FPM" ]]; then
  echo "ERROR: $FPM not found"
  exit 1
fi

for svc in php82-fpm php-fpm82 php8.2-fpm; do
  if systemctl list-unit-files "${svc}.service" &>/dev/null; then
    echo "Starting systemd unit: ${svc}"
    systemctl enable "${svc}" 2>/dev/null || true
    systemctl start "${svc}"
    systemctl status "${svc}" --no-pager | head -12
    ls -la "$SOCK" 2>/dev/null && exit 0
  fi
done

echo "Starting php-fpm directly..."
"$FPM" --nodaemonize --fpm-config "$CONF" &
sleep 2

if [[ -S "$SOCK" ]]; then
  ls -la "$SOCK"
  ps aux | grep '[p]hp-fpm'
  echo "OK — reload Apache: systemctl reload apache2"
else
  echo "FAILED — check logs:"
  tail -30 /opt/php82/var/log/php-fpm.log 2>/dev/null || true
  "$FPM" -t 2>&1 || true
  exit 1
fi
