<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->enum('platform', [
                'facebook', 'instagram', 'youtube',
                'google_analytics', 'search_console',
                'google_business', 'linkedin', 'twitter',
                'mailchimp', 'slack'
            ]);
            $table->string('account_id');
            $table->string('account_name')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->boolean('is_connected')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->enum('connection_status', ['connected', 'expired', 'error', 'pending_auth'])->default('pending_auth');
            $table->text('error_message')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'platform', 'account_id']);
            $table->index('workspace_id');
            $table->index('is_connected');
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
