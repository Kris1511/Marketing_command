<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_posts', 'platform_statuses')) {
                $table->json('platform_statuses')->nullable()->after('error_message');
            }
            if (!Schema::hasColumn('facebook_posts', 'ig_media_id')) {
                $table->string('ig_media_id')->nullable()->after('fb_post_id');
            }
            if (!Schema::hasColumn('facebook_posts', 'yt_video_id')) {
                $table->string('yt_video_id')->nullable()->after('ig_media_id');
            }
            if (!Schema::hasColumn('facebook_posts', 'youtube_privacy')) {
                $table->string('youtube_privacy')->nullable()->default('unlisted')->after('yt_video_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            $table->dropColumn(['platform_statuses', 'ig_media_id', 'yt_video_id', 'youtube_privacy']);
        });
    }
};
