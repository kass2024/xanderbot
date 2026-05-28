<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Applies pending ads-related migrations when columns are missing (safe on boot).
 */
class EnsureAdsSchema
{
    public static function run(): void
    {
        if (! Schema::hasTable('ads')) {
            return;
        }

        $requiredOnAds = [
            'ctr',
            'daily_budget',
            'daily_spend',
            'spend_date',
            'pause_reason',
            'daily_spend_anchor',
        ];

        $missing = array_values(array_filter(
            $requiredOnAds,
            fn (string $column) => ! Schema::hasColumn('ads', $column)
        ));

        $needsMetaId = Schema::hasTable('ad_sets') && ! Schema::hasColumn('ad_sets', 'meta_id');
        $needsCreativeMetaId = Schema::hasTable('creatives') && ! Schema::hasColumn('creatives', 'meta_id');

        if ($missing === [] && ! $needsMetaId && ! $needsCreativeMetaId) {
            return;
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            Log::info('ADS_SCHEMA_AUTO_MIGRATED', [
                'missing_columns' => $missing,
                'ad_sets_meta_id' => $needsMetaId,
                'creatives_meta_id' => $needsCreativeMetaId,
            ]);
        } catch (Throwable $e) {
            Log::warning('ADS_SCHEMA_AUTO_MIGRATE_FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
