<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facebook Data Deletion support.
     *
     * - facebook_pages / integrations remember WHICH Facebook user (app-scoped id) and WHICH Meta app granted the
     *   connection, so a deletion request from Meta can be matched to exactly that user's connections.
     *   Rows created before this migration have NULL here and cannot be matched automatically.
     * - facebook_data_deletion_requests tracks every request. Only a keyed hash of the Facebook user id is kept,
     *   so the raw id is not retained after the data has been deleted.
     */
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->string('facebook_user_id', 64)->nullable()->after('page_access_token');
            $table->string('facebook_app_id', 64)->nullable()->after('facebook_user_id');
            $table->index(['facebook_app_id', 'facebook_user_id'], 'facebook_pages_fb_user_index');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->string('facebook_user_id', 64)->nullable()->after('account_name');
            $table->string('facebook_app_id', 64)->nullable()->after('facebook_user_id');
            $table->index(['facebook_app_id', 'facebook_user_id'], 'integrations_fb_user_index');
        });

        Schema::create('facebook_data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 40)->unique();
            $table->string('facebook_app_id', 64);
            $table->char('facebook_user_hash', 64);
            $table->string('status', 20)->default('received');
            $table->json('deleted_counts')->nullable();
            $table->unsignedInteger('request_count')->default(1);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // One tracking record per (Meta app, Facebook user): repeated requests reuse the same confirmation code.
            $table->unique(['facebook_app_id', 'facebook_user_hash'], 'fb_deletion_app_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_data_deletion_requests');

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropIndex('integrations_fb_user_index');
            $table->dropColumn(['facebook_user_id', 'facebook_app_id']);
        });

        Schema::table('facebook_pages', function (Blueprint $table) {
            $table->dropIndex('facebook_pages_fb_user_index');
            $table->dropColumn(['facebook_user_id', 'facebook_app_id']);
        });
    }
};
