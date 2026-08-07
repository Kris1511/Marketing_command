<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Facebook Pages Table
        Schema::create('facebook_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->string('page_id')->index();
            $table->string('page_name');
            $table->text('page_access_token'); // Encrypted at model layer
            $table->unsignedBigInteger('followers_count')->default(0);
            $table->unsignedBigInteger('fan_count')->default(0);
            $table->text('profile_picture_url')->nullable();
            $table->timestamp('connected_since')->useCurrent();
            $table->enum('token_status', ['valid', 'expiring_soon', 'invalid', 'revoked'])->default('valid');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'page_id']);
        });

        // 2. Facebook Posts Table
        Schema::create('facebook_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('facebook_page_id')->nullable()->constrained('facebook_pages')->onDelete('set null');
            $table->enum('post_type', ['text', 'single_image', 'multi_image', 'video', 'link'])->default('text');
            $table->longText('content')->nullable();
            $table->text('link_url')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'published', 'failed'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('fb_post_id')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index('scheduled_at');
        });

        // 3. Facebook Post Media Attachments Table
        Schema::create('facebook_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_post_id')->constrained('facebook_posts')->onDelete('cascade');
            $table->enum('media_type', ['image', 'video'])->default('image');
            $table->text('file_path')->nullable();
            $table->text('file_url')->nullable();
            $table->string('fb_media_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 4. Facebook Post Execution & Retry History Table
        Schema::create('facebook_post_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_post_id')->constrained('facebook_posts')->onDelete('cascade');
            $table->enum('action', ['published', 'failed', 'retried', 'edited', 'scheduled'])->default('published');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->integer('status_code')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_post_history');
        Schema::dropIfExists('facebook_post_media');
        Schema::dropIfExists('facebook_posts');
        Schema::dropIfExists('facebook_pages');
    }
};
