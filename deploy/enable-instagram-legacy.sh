#!/usr/bin/env bash
# Stable Instagram delivery for legacy campaigns + ad sets + new ads.
# Creates new Meta ad ids where today shows FB/Audience Network but no Instagram.
#
#   cd /var/www/xanderbot && sudo bash deploy/enable-instagram-legacy.sh

set -euo pipefail

cd "$(dirname "$0")/.."

php artisan optimize:clear
php artisan meta:enable-instagram --force-adsets --reprovision
php artisan meta:backfill-ig-enabled
php artisan optimize:clear

echo ""
echo "Done. Hard-refresh Ads Manager. Check Platforms = IG enabled; IG live after Meta reports instagram impressions on the NEW ad id."
