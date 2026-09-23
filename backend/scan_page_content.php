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

echo "=== 1. FETCHING ALL POSTS & VIDEOS FROM PAGE {$pageId} ===\n";

// 1. Fetch published posts from feed
$feedRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/feed", [
    'fields' => 'id,message,created_time,shares,comments.summary(true),reactions.summary(true),attachments{media_type,type,unshimmed_url}',
    'limit' => 30,
    'access_token' => $token,
]);

$feed = $feedRes->json('data') ?? [];
echo "Feed items count: " . count($feed) . "\n";

foreach ($feed as $item) {
    echo "Post {$item['id']} | Created: {$item['created_time']} | Message: " . substr($item['message'] ?? 'No msg', 0, 40) . "\n";
}

// 2. Fetch videos from /videos
$videosRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/videos", [
    'fields' => 'id,title,description,created_time,views,length',
    'limit' => 30,
    'access_token' => $token,
]);

$videos = $videosRes->json('data') ?? [];
echo "\nVideos count: " . count($videos) . "\n";
foreach ($videos as $v) {
    echo "Video {$v['id']} | Created: {$v['created_time']} | Views field: " . ($v['views'] ?? 'N/A') . " | Title: " . ($v['title'] ?? substr($v['description'] ?? '', 0, 30)) . "\n";
}

// 3. Fetch video_reels
$reelsRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/video_reels", [
    'fields' => 'id,created_time',
    'limit' => 30,
    'access_token' => $token,
]);
$reels = $reelsRes->json('data') ?? [];
echo "\nReels count: " . count($reels) . "\n";
