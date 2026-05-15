#!/usr/bin/env bash
# Shared PHP paths for this server (custom /opt/php82 vs system apt PHP).
# Source from other scripts:  source "$(dirname "$0")/php-env.sh"

if [[ -x /opt/php82/bin/php ]]; then
  export PHP_BIN="/opt/php82/bin/php"
  export PHP_FPM_BIN="/opt/php82/sbin/php-fpm"
else
  export PHP_BIN="${PHP_BIN:-php}"
  export PHP_FPM_BIN="${PHP_FPM_BIN:-php-fpm}"
fi

# Returns socket path or host:port from php-fpm pool config
php_fpm_listen_from_config() {
  local conf listen
  for conf in \
    /opt/php82/etc/php-fpm.d/www.conf \
    /opt/php82/etc/php-fpm.d/*.conf \
    /opt/php82/etc/php-fpm.conf \
    /etc/php/8.2/fpm/pool.d/www.conf \
    /etc/php/8.1/fpm/pool.d/www.conf; do
    [[ -f "$conf" ]] || continue
    listen=$(grep -E '^[;]*\s*listen\s*=' "$conf" 2>/dev/null | grep -v '^\s*;' | tail -1 | sed -E 's/^[;]*\s*listen\s*=\s*//; s/^\s+|\s+$//')
    if [[ -n "$listen" ]]; then
      echo "$listen"
      return 0
    fi
  done
  return 1
}

detect_php_fpm_socket() {
  local listen sock

  listen=$(php_fpm_listen_from_config 2>/dev/null || true)
  if [[ -n "$listen" ]]; then
    if [[ "$listen" == /* ]] && [[ -S "$listen" ]]; then
      echo "$listen"
      return 0
    fi
    if [[ "$listen" == /* ]]; then
      echo "$listen"
      return 0
    fi
    if [[ "$listen" == *:* ]]; then
      echo "tcp:$listen"
      return 0
    fi
  fi

  for sock in \
    /opt/php82/var/run/php-fpm.sock \
    /opt/php82/var/run/php82-fpm.sock \
    /opt/php82/run/php-fpm.sock \
    /run/php82-fpm.sock \
    /run/php-fpm82.sock \
    /run/php/php8.2-fpm.sock \
    /run/php/php8.1-fpm.sock; do
    if [[ -S "$sock" ]]; then
      echo "$sock"
      return 0
    fi
  done

  return 1
}

apache_php_sethandler() {
  local listen="$1"
  if [[ "$listen" == tcp:* ]]; then
    local hostport="${listen#tcp:}"
    echo "SetHandler \"proxy:fcgi://${hostport}\""
  else
    echo "SetHandler \"proxy:unix:${listen}|fcgi://localhost\""
  fi
}

restart_php_fpm() {
  for svc in php82-fpm php-fpm82 php8.2-fpm php-fpm; do
    if systemctl is-enabled "$svc" &>/dev/null || systemctl list-unit-files "$svc.service" &>/dev/null; then
      systemctl restart "$svc" 2>/dev/null && return 0
    fi
  done
  if [[ -x /opt/php82/sbin/php-fpm ]]; then
    pkill -f '/opt/php82/sbin/php-fpm' 2>/dev/null || true
    /opt/php82/sbin/php-fpm --daemonize 2>/dev/null || true
  fi
}
