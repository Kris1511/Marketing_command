<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_pages', 'reach_count')) {
                $table->unsignedBigInteger('reach_count')->default(0)->after('fan_count');
                $table->timestamp('last_synced_at')->nullable()->after('reach_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropColumn(['reach_count', 'last_synced_at']);
        });
    }
};
