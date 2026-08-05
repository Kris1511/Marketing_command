<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_workspace_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->enum('role', ['admin', 'manager', 'executive', 'viewer'])->default('viewer');
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id']);
            $table->index('workspace_id');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_workspace_roles');
    }
};
