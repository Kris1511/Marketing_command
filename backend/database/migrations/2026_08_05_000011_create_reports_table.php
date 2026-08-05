<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('created_by_id')->constrained('users')->onDelete('restrict');
            $table->string('title');
            $table->enum('report_type', ['overview', 'channel_performance', 'lead_summary', 'roi_analysis', 'custom']);
            $table->json('filters')->nullable();
            $table->json('data')->nullable();
            $table->enum('export_format', ['pdf', 'csv', 'json'])->default('pdf');
            $table->string('file_path', 500)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('generated_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
