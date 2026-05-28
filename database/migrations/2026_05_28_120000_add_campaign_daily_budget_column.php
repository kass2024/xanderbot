<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaigns')) {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('campaigns', 'daily_budget')) {
                $table->unsignedBigInteger('daily_budget')->nullable()->after('objective');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('campaigns')) {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('campaigns', 'daily_budget')) {
                $table->dropColumn('daily_budget');
            }
        });
    }
};
