<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$postId1 = '115864121526929_982499514861065'; // Post 1 (17 Sep)
$postId3 = '115864121526929_977067722070911'; // Post 3 (11 Sep)

// 1. Without period
$r1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/insights", [
        'metric'       => 'post_media_view',
        'access_token' => $token,
    ]);
echo "Without period Post 1: " . $r1->status() . " -> " . $r1->body() . "\n";

// 2. What metrics DOES this post have in insights?
// If we pass an invalid metric like 'foo', Meta returns the list of valid metrics in the error message!
$rErr = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/insights", [
        'metric'       => 'all_valid_metrics_test',
        'access_token' => $token,
    ]);
echo "Error inspection on Post 1: " . $rErr->body() . "\n";
