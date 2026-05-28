#!/usr/bin/env bash
# Run ONLY additive migrations (safe when tables already exist).
# Do NOT run full `php artisan migrate` on production if create_* migrations were never recorded.
#
# Usage on server:
#   cd /var/www/xanderbot && sudo bash deploy/migrate-pending-columns.sh

set -euo pipefail

cd "$(dirname "$0")/.."

MIGRATIONS=(
  "database/migrations/2026_05_26_100000_add_ads_budget_and_metrics_columns.php"
  "database/migrations/2026_05_25_120000_add_daily_spend_anchor_to_ads.php"
  "database/migrations/2026_05_28_100000_align_meta_ads_schema.php"
  "database/migrations/2026_05_28_120000_add_campaign_daily_budget_column.php"
  "database/migrations/2026_05_29_120000_add_ads_instagram_enabled_at.php"
)

echo "=== Current migration status (last 15) ==="
php artisan migrate:status 2>/dev/null | tail -15 || true

echo ""
for path in "${MIGRATIONS[@]}"; do
  if [[ ! -f "$path" ]]; then
    echo "SKIP (missing): $path"
    continue
  fi
  echo "=== Running: $path ==="
  php artisan migrate --path="$path" --force
done

echo ""
echo "=== Verify ads columns ==="
php artisan tinker --execute="
\$cols = Illuminate\Support\Facades\Schema::getColumnListing('ads');
echo in_array('daily_spend', \$cols) ? 'daily_spend OK' : 'MISSING daily_spend';
echo PHP_EOL;
echo in_array('daily_spend_anchor', \$cols) ? 'daily_spend_anchor OK' : 'MISSING daily_spend_anchor';
echo PHP_EOL;
echo in_array('pause_reason', \$cols) ? 'pause_reason OK' : 'MISSING pause_reason';
echo PHP_EOL;
echo in_array('instagram_enabled_at', \$cols) ? 'instagram_enabled_at OK' : 'MISSING instagram_enabled_at';
echo PHP_EOL;
"

echo "Done."
