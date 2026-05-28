#!/usr/bin/env bash
# Verify Page ↔ Instagram, then update all existing ad sets, creatives, and ads on Meta.
set -euo pipefail

cd /var/www/xanderbot

php artisan config:clear
php artisan optimize:clear

php artisan meta:verify-instagram || exit 1
php artisan meta:enable-instagram
