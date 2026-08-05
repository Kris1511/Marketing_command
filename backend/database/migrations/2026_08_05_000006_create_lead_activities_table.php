<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->enum('activity_type', ['call', 'email', 'sms', 'meeting', 'note', 'status_change', 'assignment_change']);
            $table->text('activity_notes')->nullable();
            $table->dateTime('activity_date');
            $table->timestamps();

            $table->index('lead_id');
            $table->index('activity_date');
            $table->index('activity_type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
