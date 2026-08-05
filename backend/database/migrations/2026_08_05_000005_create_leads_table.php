<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->onDelete('set null');
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->enum('status', ['new', 'contacted', 'qualified', 'converted', 'lost', 'ignored'])->default('new');
            $table->enum('source', [
                'facebook_lead_ad', 'google_lead_form', 'website_form',
                'manual_entry', 'instagram', 'linkedin', 'email', 'phone_call', 'other'
            ]);
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('status');
            $table->index('source');
            $table->index('assigned_to_id');
            $table->index('created_at');
            $table->index(['email', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
