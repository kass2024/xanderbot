<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads') && ! Schema::hasColumn('ads', 'instagram_enabled_at')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->timestamp('instagram_enabled_at')->nullable()->after('pause_reason');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ads') && Schema::hasColumn('ads', 'instagram_enabled_at')) {
            Schema::table('ads', function (Blueprint $table) {
                $table->dropColumn('instagram_enabled_at');
            });
        }
    }
};
