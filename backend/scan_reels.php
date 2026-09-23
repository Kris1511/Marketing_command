<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\FacebookPage;

$page = FacebookPage::where('workspace_id', 9)->where('page_id', '115864121526929')->first();
$token = $page->page_access_token;
$pageId = $page->page_id;

echo "=== SCANNING ALL 16 REELS ===\n";

$reelsRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/video_reels", [
    'fields' => 'id,created_time,description,views,video_insights',
    'limit' => 30,
    'access_token' => $token,
]);

$reels = $reelsRes->json('data') ?? [];
echo "Raw response: " . $reelsRes->body() . "\n";
echo "Fetched " . count($reels) . " reels.\n";


foreach ($reels as $r) {
    $rId = $r['id'];
    // Query insights for this reel
    $insRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$rId}/video_insights", [
        'access_token' => $token,
    ]);
    
    $insData = $insRes->json('data') ?? [];
    $metricsSummary = [];
    foreach ($insData as $metric) {
        $mName = $metric['name'] ?? 'unknown';
        $mVal = $metric['values'][0]['value'] ?? 0;
        $metricsSummary[] = "{$mName}={$mVal}";
    }
    
    echo "Reel {$rId} | Created: {$r['created_time']} | Insights: " . implode(', ', $metricsSummary) . " | Desc: " . substr($r['description'] ?? '', 0, 30) . "\n";
}
