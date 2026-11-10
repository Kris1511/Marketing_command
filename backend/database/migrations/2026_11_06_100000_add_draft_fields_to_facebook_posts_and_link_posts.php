<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publishing QA fixes B4/B6/B12:
     *  - facebook_posts keeps the composer fields that used to be dropped (internal title, CTA, hashtags,
     *    YouTube title) plus the target platforms, so drafts can be re-opened exactly as saved.
     *  - posts (legacy history table) gets a real link to facebook_posts instead of being matched by content.
     * Every column is nullable, so existing rows keep working untouched.
     */
    public function up(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('facebook_posts', 'internal_title')) {
                $table->string('internal_title', 150)->nullable();
            }
            if (! Schema::hasColumn('facebook_posts', 'cta')) {
                $table->string('cta', 50)->nullable();
            }
            if (! Schema::hasColumn('facebook_posts', 'hashtags')) {
                $table->text('hashtags')->nullable();
            }
            if (! Schema::hasColumn('facebook_posts', 'video_title')) {
                $table->string('video_title', 100)->nullable();
            }
            if (! Schema::hasColumn('facebook_posts', 'platforms')) {
                $table->json('platforms')->nullable();
            }
        });

        Schema::table('posts', function (Blueprint $table) {
            if (! Schema::hasColumn('posts', 'facebook_post_id')) {
                $table->unsignedBigInteger('facebook_post_id')->nullable()->index();
            }
        });

        $this->backfillLinks();
    }

    /**
     * One-time, idempotent link of pre-existing rows. The old code paired a post with its history row
     * by identical caption text; here each legacy row is claimed by AT MOST ONE post (oldest first), so two
     * posts with the same caption can no longer share - or swap - platforms. Rows without a match are untouched.
     */
    private function backfillLinks(): void
    {
        $posts = DB::table('facebook_posts')->whereNull('platforms')->orderBy('id')->get(['id', 'workspace_id', 'content']);

        foreach ($posts as $post) {
            $legacy = DB::table('posts')
                ->where('workspace_id', $post->workspace_id)
                ->where('content', $post->content)
                ->whereNull('facebook_post_id')
                ->orderBy('id')
                ->first(['id', 'platform_list']);

            if (! $legacy) {
                continue;
            }

            DB::table('posts')->where('id', $legacy->id)->update(['facebook_post_id' => $post->id]);
            if (! empty($legacy->platform_list)) {
                DB::table('facebook_posts')->where('id', $post->id)->update(['platforms' => $legacy->platform_list]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('facebook_posts', function (Blueprint $table) {
            foreach (['internal_title', 'cta', 'hashtags', 'video_title', 'platforms'] as $col) {
                if (Schema::hasColumn('facebook_posts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'facebook_post_id')) {
                $table->dropIndex(['facebook_post_id']);
                $table->dropColumn('facebook_post_id');
            }
        });
    }
};
