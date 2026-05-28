#!/usr/bin/env bash
# Update all existing Meta campaigns (ad sets), creatives, and ads for Instagram delivery.
set -euo pipefail

cd /var/www/xanderbot

php artisan meta:enable-instagram
