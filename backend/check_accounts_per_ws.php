<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$pages = App\Models\FacebookPage::all(['id', 'workspace_id', 'page_id', 'page_name', 'token_status']);
echo "=== Facebook Pages ===\n";
foreach ($pages as $p) {
    echo "ID: {$p->id} | WS: {$p->workspace_id} | Page ID: {$p->page_id} | Name: {$p->page_name} | Status: {$p->token_status}\n";
}

$ytConns = App\Models\YouTubeConnection::all(['id', 'workspace_id', 'channel_id', 'channel_name']);
echo "=== YouTube Connections ===\n";
foreach ($ytConns as $y) {
    echo "ID: {$y->id} | WS: {$y->workspace_id} | Channel ID: {$y->channel_id} | Name: {$y->channel_name}\n";
}
