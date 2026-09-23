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

$reelsRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/video_reels", [
    'fields' => 'id,created_time',
    'limit' => 30,
    'access_token' => $token,
]);

$reels = $reelsRes->json('data') ?? [];
echo "Fetched " . count($reels) . " reels.\n";

$totalViews = 0;
foreach ($reels as $r) {
    $rId = $r['id'];
    
    // Fetch video details for this reel
    $vRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$rId}", [
        'fields' => 'id,title,description,created_time,views,post_views',
        'access_token' => $token,
    ]);
    
    $vData = $vRes->json();
    $views = $vData['views'] ?? null;
    $postViews = $vData['post_views'] ?? null;
    $created = $vData['created_time'] ?? $r['created_time'];
    $title = substr($vData['title'] ?? ($vData['description'] ?? 'No title'), 0, 30);
    
    echo "Reel {$rId} | Created: {$created} | views: " . var_export($views, true) . " | post_views: " . var_export($postViews, true) . " | Title: {$title}\n";
    
    if (is_numeric($views)) {
        $totalViews += $views;
    }
}

echo "\nTotal views sum of all reels: {$totalViews}\n";
