<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_messenger_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('facebook_page_id')->nullable()->constrained('facebook_pages')->nullOnDelete();
            $table->string('page_id')->index();
            $table->string('sender_id')->nullable()->index();
            $table->string('recipient_id')->nullable()->index();
            $table->string('message_id')->nullable()->index();
            $table->string('event_key')->unique();
            $table->string('event_type')->default('message')->index();
            $table->unsignedBigInteger('event_timestamp')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_messenger_webhook_events');
    }
};
