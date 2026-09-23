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

$endpoints = [
    'published_posts' => "https://graph.facebook.com/v23.0/{$pageId}/published_posts?fields=id,message,created_time,shares,reactions.summary(true),comments.summary(true)&access_token={$token}",
    'feed' => "https://graph.facebook.com/v23.0/{$pageId}/feed?fields=id,message,created_time&access_token={$token}",
    'posts' => "https://graph.facebook.com/v23.0/{$pageId}/posts?fields=id,message,created_time&access_token={$token}",
    'photos' => "https://graph.facebook.com/v23.0/{$pageId}/photos?fields=id,created_time,name&type=uploaded&access_token={$token}",
];

foreach ($endpoints as $name => $url) {
    $res = Http::withoutVerifying()->get($url);
    $data = $res->json('data') ?? [];
    echo "=== {$name} (count: " . count($data) . ") ===\n";
    if ($res->failed()) {
        echo "Error: " . $res->body() . "\n";
    } else {
        foreach (array_slice($data, 0, 10) as $item) {
            $msg = substr($item['message'] ?? ($item['name'] ?? 'No text'), 0, 40);
            echo "  ID: {$item['id']} | Time: {$item['created_time']} | {$msg}\n";
        }
    }
    echo "\n";
}
