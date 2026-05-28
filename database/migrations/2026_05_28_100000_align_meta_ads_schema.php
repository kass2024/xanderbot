<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_accounts') && ! Schema::hasColumn('ad_accounts', 'meta_id')) {
            Schema::table('ad_accounts', function (Blueprint $table) {
                $table->string('meta_id')->nullable()->after('ad_account_id');
            });

            if (Schema::hasColumn('ad_accounts', 'ad_account_id')) {
                DB::table('ad_accounts')
                    ->whereNull('meta_id')
                    ->whereNotNull('ad_account_id')
                    ->update([
                        'meta_id' => DB::raw("CONCAT('act_', ad_account_id)"),
                    ]);
            }
        }

        if (Schema::hasTable('ad_sets')) {
            Schema::table('ad_sets', function (Blueprint $table) {
                if (! Schema::hasColumn('ad_sets', 'meta_id')) {
                    $table->string('meta_id')->nullable()->index()->after('campaign_id');
                }
            });

            if (Schema::hasColumn('ad_sets', 'meta_adset_id')) {
                DB::table('ad_sets')
                    ->whereNull('meta_id')
                    ->whereNotNull('meta_adset_id')
                    ->update(['meta_id' => DB::raw('meta_adset_id')]);
            }
        }

        if (Schema::hasTable('creatives')) {
            Schema::table('creatives', function (Blueprint $table) {
                if (! Schema::hasColumn('creatives', 'meta_id')) {
                    $table->string('meta_id')->nullable()->index()->after('id');
                }
                if (! Schema::hasColumn('creatives', 'campaign_id')) {
                    $table->foreignId('campaign_id')->nullable()->after('meta_id');
                }
                if (! Schema::hasColumn('creatives', 'adset_id')) {
                    $table->foreignId('adset_id')->nullable()->after('campaign_id');
                }
                if (! Schema::hasColumn('creatives', 'headline')) {
                    $table->string('headline')->nullable()->after('name');
                }
                if (! Schema::hasColumn('creatives', 'image_hash')) {
                    $table->string('image_hash')->nullable()->after('image_url');
                }
                if (! Schema::hasColumn('creatives', 'status')) {
                    $table->string('status')->default('DRAFT')->after('json_payload');
                }
                if (! Schema::hasColumn('creatives', 'review_status')) {
                    $table->string('review_status')->nullable();
                }
                if (! Schema::hasColumn('creatives', 'effective_status')) {
                    $table->string('effective_status')->nullable();
                }
            });

            if (Schema::hasColumn('creatives', 'meta_creative_id')) {
                DB::table('creatives')
                    ->whereNull('meta_id')
                    ->whereNotNull('meta_creative_id')
                    ->update(['meta_id' => DB::raw('meta_creative_id')]);
            }
        }

        if (Schema::hasTable('campaigns')) {
            Schema::table('campaigns', function (Blueprint $table) {
                if (! Schema::hasColumn('campaigns', 'meta_id')) {
                    $table->string('meta_id')->nullable()->index()->after('id');
                }
                if (! Schema::hasColumn('campaigns', 'ad_account_id')) {
                    $table->foreignId('ad_account_id')->nullable()->after('client_id');
                }
            });
        }

        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'pause_reason')) {
            DB::table('ads')
                ->where('pause_reason', 'budget')
                ->update(['pause_reason' => 'budget_limit']);
        }
    }

    public function down(): void
    {
        // Non-destructive alignment migration; columns kept on rollback.
    }
};
