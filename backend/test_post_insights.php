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

$metrics = [
    'post_impressions',
    'post_impressions_unique',
    'post_engaged_users',
    'post_reactions_by_type_total',
    'post_activity_by_action_type',
    'post_clicks',
];

echo "=== Testing post insights on Post 1 ===\n";
foreach ($metrics as $m) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()
        ->timeout(10)
        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
        ->get("https://graph.facebook.com/{$version}/{$postId1}/insights", [
            'metric' => $m,
            'access_token' => $token,
        ]);
    echo "Metric {$m}: status " . $res->status() . " -> " . substr($res->body(), 0, 300) . "\n";
}

echo "\n=== Testing post insights on Post 3 ===\n";
foreach (['post_impressions', 'post_impressions_unique', 'post_reactions_by_type_total'] as $m) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()
        ->timeout(10)
        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
        ->get("https://graph.facebook.com/{$version}/{$postId3}/insights", [
            'metric' => $m,
            'access_token' => $token,
        ]);
    echo "Metric {$m}: status " . $res->status() . " -> " . substr($res->body(), 0, 300) . "\n";
}
