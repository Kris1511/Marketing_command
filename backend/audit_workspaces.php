<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$workspaces = App\Models\Workspace::all(['id', 'name', 'status']);
echo "=== Workspaces ===\n";
foreach ($workspaces as $w) {
    echo "ID: {$w->id} | Name: {$w->name}\n";
    $fbCount = App\Models\FacebookPage::where('workspace_id', $w->id)->count();
    $fbPosts = App\Models\FacebookPost::where('workspace_id', $w->id)->count();
    $ytCount = App\Models\YouTubeConnection::where('workspace_id', $w->id)->count();
    $integs  = App\Models\Integration::where('workspace_id', $w->id)->get(['platform', 'account_name', 'is_connected', 'connection_status'])->toArray();
    echo "  - Facebook Pages: {$fbCount}, Facebook Posts: {$fbPosts}\n";
    echo "  - YouTube Connections: {$ytCount}\n";
    echo "  - Integrations:\n";
    foreach ($integs as $i) {
        echo "      * {$i['platform']}: '{$i['account_name']}' (connected: " . ($i['is_connected'] ? 'true' : 'false') . ", status: {$i['connection_status']})\n";
    }
}
