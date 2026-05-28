<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ads') || Schema::hasColumn('ads', 'previous_meta_ad_ids')) {
            return;
        }

        Schema::table('ads', function (Blueprint $table) {
            $table->json('previous_meta_ad_ids')->nullable()->after('meta_ad_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'previous_meta_ad_ids')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->dropColumn('previous_meta_ad_ids');
            });
        }
    }
};
