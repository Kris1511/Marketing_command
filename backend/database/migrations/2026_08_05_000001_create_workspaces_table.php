<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('primary_contact')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->decimal('budget', 12, 2)->unsigned()->nullable();
            $table->enum('status', ['active', 'paused', 'archived', 'inactive', 'pending'])->default('active');
            $table->string('logo_url', 500)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->onDelete('set null');
            $table->softDeletes();
            $table->timestamps();

            $table->index('owner_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
