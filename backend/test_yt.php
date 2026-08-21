<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ytConn = App\Models\YouTubeConnection::first();
echo "ytConn exists: " . ($ytConn ? "yes (ID {$ytConn->id}, channel: {$ytConn->channel_name})" : "no") . "\n";
echo "ytConn user_id: " . $ytConn->user_id . "\n";
echo "ytConn access_token length: " . strlen($ytConn->access_token ?? '') . "\n";
echo "ytConn refresh_token length: " . strlen($ytConn->refresh_token ?? '') . "\n";
echo "ytConn token_expires_at: " . $ytConn->token_expires_at . "\n";

$ytService = new App\Services\YouTubeService();
try {
    $vm = $ytService->getVideoMetrics($ytConn);
    echo "Video metrics: " . json_encode($vm) . "\n";
} catch (\Throwable $e) {
    echo "Video metrics error: " . $e->getMessage() . "\n";
}
try {
    $an = $ytService->getAnalyticsOverview($ytConn);
    echo "Analytics overview: " . json_encode($an) . "\n";
} catch (\Throwable $e) {
    echo "Analytics error: " . $e->getMessage() . "\n";
}
