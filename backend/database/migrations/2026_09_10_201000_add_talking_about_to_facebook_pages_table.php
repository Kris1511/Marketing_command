<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('facebook_pages')) {
            Schema::table('facebook_pages', function (Blueprint $table) {
                if (!Schema::hasColumn('facebook_pages', 'talking_about_count')) {
                    $table->unsignedInteger('talking_about_count')->default(0)->after('fan_count');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('facebook_pages')) {
            Schema::table('facebook_pages', function (Blueprint $table) {
                if (Schema::hasColumn('facebook_pages', 'talking_about_count')) {
                    $table->dropColumn('talking_about_count');
                }
            });
        }
    }
};
