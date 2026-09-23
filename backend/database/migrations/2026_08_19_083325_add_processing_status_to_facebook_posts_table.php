<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'processing' to the facebook_posts status enum.
     * Used as an atomic lock to prevent the same post from being published
     * twice if the scheduler fires while a previous run is still in progress.
     *
     * State machine:  scheduled → processing → published | failed
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE facebook_posts MODIFY COLUMN status ENUM('draft','scheduled','processing','published','failed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // First reset any stuck 'processing' rows back to 'scheduled'
        DB::statement("UPDATE facebook_posts SET status = 'scheduled' WHERE status = 'processing'");
        DB::statement("ALTER TABLE facebook_posts MODIFY COLUMN status ENUM('draft','scheduled','published','failed') NOT NULL DEFAULT 'draft'");
    }
};
