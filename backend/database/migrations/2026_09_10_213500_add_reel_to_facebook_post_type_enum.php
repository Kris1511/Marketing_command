<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'reel' to the facebook_posts post_type enum.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE facebook_posts MODIFY COLUMN post_type ENUM('text','single_image','multi_image','video','reel','link') NOT NULL DEFAULT 'text'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE facebook_posts SET post_type = 'video' WHERE post_type = 'reel'");
            DB::statement("ALTER TABLE facebook_posts MODIFY COLUMN post_type ENUM('text','single_image','multi_image','video','link') NOT NULL DEFAULT 'text'");
        }
    }
};
