<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Add 'disconnected' to facebook_pages.token_status enum
        DB::statement("ALTER TABLE `facebook_pages` MODIFY COLUMN `token_status` ENUM('valid', 'expiring_soon', 'invalid', 'revoked', 'disconnected') NOT NULL DEFAULT 'valid'");

        // 2. Add 'disconnected' to integrations.connection_status enum
        DB::statement("ALTER TABLE `integrations` MODIFY COLUMN `connection_status` ENUM('connected', 'expired', 'error', 'pending_auth', 'disconnected') NOT NULL DEFAULT 'pending_auth'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `facebook_pages` MODIFY COLUMN `token_status` ENUM('valid', 'expiring_soon', 'invalid', 'revoked') NOT NULL DEFAULT 'valid'");
        DB::statement("ALTER TABLE `integrations` MODIFY COLUMN `connection_status` ENUM('connected', 'expired', 'error', 'pending_auth') NOT NULL DEFAULT 'pending_auth'");
    }
};
