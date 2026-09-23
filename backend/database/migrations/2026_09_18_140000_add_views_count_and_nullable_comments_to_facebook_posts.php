<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_posts', 'views_count')) {
                $table->unsignedBigInteger('views_count')->nullable()->after('reach_count');
            }
        });

        // Make comments_count nullable so that missing permissions can be represented as null ('—')
        try {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE `facebook_posts` MODIFY `comments_count` INT UNSIGNED NULL DEFAULT NULL");
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (Schema::hasColumn('facebook_posts', 'views_count')) {
                $table->dropColumn('views_count');
            }
        });
    }
};
