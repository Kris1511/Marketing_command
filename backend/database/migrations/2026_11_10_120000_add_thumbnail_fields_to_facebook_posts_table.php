<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom thumbnail / cover image for video posts, stored separately from the video itself so it survives
 * draft save/restore, scheduling and scheduled publishing.
 *   thumbnail_path: path on the "public" disk (e.g. posts/6/thumbnails/<uuid>.jpg)
 *   thumbnail_url:  public URL of that file (for previews)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_posts', 'thumbnail_path')) {
                $table->string('thumbnail_path', 1024)->nullable()->after('video_title');
            }
            if (!Schema::hasColumn('facebook_posts', 'thumbnail_url')) {
                $table->string('thumbnail_url', 2048)->nullable()->after('thumbnail_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (Schema::hasColumn('facebook_posts', 'thumbnail_url')) {
                $table->dropColumn('thumbnail_url');
            }
            if (Schema::hasColumn('facebook_posts', 'thumbnail_path')) {
                $table->dropColumn('thumbnail_path');
            }
        });
    }
};
