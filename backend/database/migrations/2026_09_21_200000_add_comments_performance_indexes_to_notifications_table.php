<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['workspace_id', 'type', 'created_at'], 'notif_ws_type_created_idx');
            $table->index(['type', 'is_read'], 'notif_type_is_read_idx');
            $table->index(['type', 'related_entity'], 'notif_type_rel_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notif_ws_type_created_idx');
            $table->dropIndex('notif_type_is_read_idx');
            $table->dropIndex('notif_type_rel_entity_idx');
        });
    }
};
