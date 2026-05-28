#!/usr/bin/env bash
# Record legacy create_* migrations as "run" when the table already exists.
# Use this ONCE on production when `php artisan migrate` fails with "Table already exists".
# Does NOT execute migration SQL — only updates the migrations table.
#
#   cd /var/www/xanderbot && sudo bash deploy/sync-legacy-migrations.sh
# Then run additive columns only:
#   sudo bash deploy/migrate-pending-columns.sh

set -euo pipefail

cd "$(dirname "$0")/.."

php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

\$pairs = [
    ['2026_02_27_114533_create_clients_table', 'clients'],
    ['2026_02_27_100000_create_users_table', 'users'],
    ['2026_02_27_132641_create_meta_connections_table', 'meta_connections'],
    ['2026_02_27_132736_create_ad_accounts_table', 'ad_accounts'],
    ['2026_02_28_044344_create_campaigns_table', 'campaigns'],
    ['2026_02_28_060218_create_chatbots_table', 'chatbots'],
    ['2026_02_28_060305_create_chatbot_triggers_table', 'chatbot_triggers'],
    ['2026_02_28_060335_create_chatbot_nodes_table', 'chatbot_nodes'],
    ['2026_02_28_060415_create_conversations_table', 'conversations'],
    ['2026_02_28_060454_create_messages_table', 'messages'],
    ['2026_02_28_060541_create_conversation_states_table', 'conversation_states'],
    ['2026_02_28_072515_create_templates_table', 'templates'],
    ['2026_02_28_084641_create_jobs_table', 'jobs'],
    ['2026_02_28_084650_create_failed_jobs_table', 'failed_jobs'],
    ['2026_02_28_090139_create_platform_meta_connections_table', 'platform_meta_connections'],
    ['2026_03_04_052931_create_ads_table', 'ads'],
    ['2026_03_04_053019_create_ad_sets_table', 'ad_sets'],
    ['2026_03_04_053230_create_creatives_table', 'creatives'],
    ['2026_03_29_000001_rename_parrot_ad_accounts_to_xander_brand', 'ad_accounts'],
];

\$batch = (int) (DB::table('migrations')->max('batch') ?? 0) + 1;
\$inserted = 0;
\$skipped = 0;

foreach (\$pairs as [\$migration, \$table]) {
    if (DB::table('migrations')->where('migration', \$migration)->exists()) {
        \$skipped++;
        continue;
    }
    if (! Schema::hasTable(\$table)) {
        echo \"SKIP (no table {\$table}): {\$migration}\" . PHP_EOL;
        continue;
    }
    DB::table('migrations')->insert([
        'migration' => \$migration,
        'batch' => \$batch,
    ]);
    echo \"RECORDED: {\$migration}\" . PHP_EOL;
    \$inserted++;
}

echo PHP_EOL . \"Inserted {\$inserted}, already recorded {\$skipped}.\" . PHP_EOL;
echo \"Next: bash deploy/migrate-pending-columns.sh\" . PHP_EOL;
"
