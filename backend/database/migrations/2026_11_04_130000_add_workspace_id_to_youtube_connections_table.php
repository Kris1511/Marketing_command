<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The YouTubeConnection model, YouTubeService and GET /workspaces all scope connections by
     * workspace_id, but the original create migration never added the column. Databases built purely
     * from migrations therefore failed every /workspaces request (the Clients page showed nothing).
     */
    public function up(): void
    {
        if (Schema::hasColumn('youtube_connections', 'workspace_id')) {
            return;
        }

        Schema::table('youtube_connections', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('youtube_connections', 'workspace_id')) {
            return;
        }

        Schema::table('youtube_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
