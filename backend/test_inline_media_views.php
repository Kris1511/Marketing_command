<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$base   = "https://graph.facebook.com/v23.0";

$res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$pageId}/published_posts", [
    'fields'       => 'id,message,created_time,shares,likes.summary(true),comments.summary(true),insights.metric(post_media_view){values}',
    'limit'        => 10,
    'access_token' => $token,
]);

echo "Status: " . $res->status() . "\n";
foreach ($res->json('data') ?? [] as $p) {
    $views = $p['insights']['data'][0]['values'][0]['value'] ?? 'N/A';
    echo sprintf("Post %-35s | Views (post_media_view): %s | Likes: %d | Comments: %d | Shares: %d\n",
        $p['id'],
        json_encode($views),
        $p['likes']['summary']['total_count'] ?? 0,
        $p['comments']['summary']['total_count'] ?? 0,
        $p['shares']['count'] ?? 0
    );
}
