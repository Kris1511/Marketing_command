<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE `facebook_posts` MODIFY `comments_count` INT UNSIGNED NULL DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            DB::statement("ALTER TABLE `facebook_posts` MODIFY `engagement_count` INT UNSIGNED NULL DEFAULT NULL");
        } catch (\Throwable $e) {}

        try {
            if (Schema::hasColumn('facebook_posts', 'views_count')) {
                DB::statement("ALTER TABLE `facebook_posts` MODIFY `views_count` BIGINT UNSIGNED NULL DEFAULT NULL");
            }
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
    }
};
