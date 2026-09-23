<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$postId1 = '115864121526929_982499514861065'; // Post 1: "4 Digital Fixes..."
$postId2 = '115864121526929_978150271962656'; // Post 2: Reel
$postId3 = '115864121526929_977067722070911'; // Post 3: "Launching a property project..."

echo "=== 1. Checking Post 1 Insights ===\n";
// Let's test calling /{post_id}/insights
$ins1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/insights", [
        'access_token' => $token,
    ]);
echo "Status: " . $ins1->status() . "\n";
echo "Body: " . substr($ins1->body(), 0, 500) . "\n";

echo "\n=== 2. Checking Post 1 Comments ===\n";
// Let's test calling /{post_id}/comments
$comm1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/comments", [
        'summary' => 'total_count',
        'filter' => 'toplevel',
        'access_token' => $token,
    ]);
echo "Status: " . $comm1->status() . "\n";
echo "Body: " . $comm1->body() . "\n";

echo "\n=== 3. Checking Post 1 Reactions ===\n";
// Let's test calling /{post_id}/reactions
$react1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/reactions", [
        'summary' => 'total_count',
        'access_token' => $token,
    ]);
echo "Status: " . $react1->status() . "\n";
echo "Body: " . $react1->body() . "\n";

echo "\n=== 4. Checking Post 1 via Page Feed / published_posts with nested fields ===\n";
// In published_posts, can we get reactions.summary(total_count) and comments.summary(total_count)?
$feed1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$fbPage->page_id}/published_posts", [
        'fields' => 'id,message,reactions.summary(total_count),comments.summary(total_count)',
        'limit' => 3,
        'access_token' => $token,
    ]);
echo "Status: " . $feed1->status() . "\n";
echo "Body: " . substr($feed1->body(), 0, 500) . "\n";
