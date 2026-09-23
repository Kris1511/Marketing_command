<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$postId1 = '115864121526929_982499514861065'; // Post 1 (17 Sep)

// What happens if we query:
// GET /v23.0/{postId1}?fields=id,message,shares,reactions.type(LIKE).summary(true),reactions.summary(true),comments.summary(true)
$res = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}", [
        'fields' => 'id,message,shares',
        'access_token' => $token,
    ]);
echo "Basic fields on Post 1: " . $res->status() . " -> " . $res->body() . "\n";

// What about /{page_id}/feed vs /{page_id}/published_posts?
// Let's check feed
$resFeed = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$fbPage->page_id}/feed", [
        'fields' => 'id,message,shares,likes.summary(true),comments.summary(true)',
        'limit' => 3,
        'access_token' => $token,
    ]);
echo "Page Feed: " . $resFeed->status() . " -> " . substr($resFeed->body(), 0, 400) . "\n";
