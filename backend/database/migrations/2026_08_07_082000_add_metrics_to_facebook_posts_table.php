<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_posts', 'likes_count')) {
                $table->unsignedInteger('likes_count')->default(0)->after('fb_post_id');
                $table->unsignedInteger('comments_count')->default(0)->after('likes_count');
                $table->unsignedInteger('shares_count')->default(0)->after('comments_count');
                $table->unsignedInteger('reactions_count')->default(0)->after('shares_count');
                $table->unsignedInteger('engagement_count')->default(0)->after('reactions_count');
                $table->unsignedInteger('reach_count')->default(0)->after('engagement_count');
                $table->timestamp('last_synced_at')->nullable()->after('reach_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            $table->dropColumn([
                'likes_count',
                'comments_count',
                'shares_count',
                'reactions_count',
                'engagement_count',
                'reach_count',
                'last_synced_at'
            ]);
        });
    }
};
