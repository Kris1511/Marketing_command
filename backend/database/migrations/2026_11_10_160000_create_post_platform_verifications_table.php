<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-platform "is this post still on the platform?" verification for the Overview dashboard.
 * Publication status per platform stays in facebook_posts.platform_statuses; this table only records what the
 * background check found (exists / not found / confirmed deleted / unable to verify) and when.
 * Rows are never used to delete posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_platform_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_post_id')->constrained('facebook_posts')->cascadeOnDelete();
            $table->string('platform', 20);                       // facebook | instagram | youtube
            $table->string('remote_id');                          // fb_post_id / ig_media_id / yt_video_id
            $table->string('account_id')->nullable();             // page / IG account / YouTube channel that owns it
            $table->string('state', 24);                          // exists | not_found | deleted | unverifiable
            $table->string('reason', 40)->nullable();             // token_expired, permission, rate_limited, temporary, account_changed, listing_incomplete, ...
            $table->text('message')->nullable();                  // platform's own error text
            $table->unsignedInteger('not_found_count')->default(0);
            $table->timestamp('first_not_found_at')->nullable();
            $table->timestamp('deleted_confirmed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();        // last time it was confirmed to exist
            $table->timestamp('last_checked_at')->nullable();     // last verification attempt
            $table->json('metrics')->nullable();                  // platform metrics read during the check (YouTube)
            $table->timestamps();

            $table->unique(['facebook_post_id', 'platform']);
            $table->index(['platform', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_platform_verifications');
    }
};
