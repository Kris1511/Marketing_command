<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "========================================================\n";
echo " DEEP META GRAPH API v23.0 FACEBOOK VIEWS INVESTIGATION\n";
echo "========================================================\n\n";

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$userInteg = \App\Models\Integration::where('platform', 'facebook')->where('is_connected', true)->first();
$userToken = $userInteg?->refresh_token ?? $token;

echo "Facebook Page ID: {$pageId}\n";
echo "Page Name: {$fbPage->page_name}\n\n";

// 1. Test Page Insights with date ranges (Today, 7 days, 30 days)
$todaySince = strtotime('today midnight UTC');
$todayUntil = time();
$thirtyDaysSince = strtotime('-30 days midnight UTC');

$metricsToTest = [
    'page_posts_impressions_organic',
    'page_posts_impressions',
    'page_posts_impressions_unique',
    'page_views_total',
    'page_impressions_organic_v2',
    'page_video_views',
];

echo "--- TEST 1: Page Insights for TODAY (since: {$todaySince}, until: {$todayUntil}) ---\n";
foreach ($metricsToTest as $metric) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/insights", [
        'metric' => $metric,
        'period' => 'day',
        'since'  => $todaySince,
        'until'  => $todayUntil,
        'access_token' => $token,
    ]);
    if ($res->successful()) {
        $data = $res->json('data.0.values') ?? [];
        echo "Metric '{$metric}': " . json_encode($data) . "\n";
    } else {
        echo "Metric '{$metric}' Error: " . json_encode($res->json('error.message')) . "\n";
    }
}

echo "\n--- TEST 2: Page Insights for LAST 30 DAYS (since: {$thirtyDaysSince}, until: {$todayUntil}) ---\n";
foreach ($metricsToTest as $metric) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/insights", [
        'metric' => $metric,
        'period' => 'day',
        'since'  => $thirtyDaysSince,
        'until'  => $todayUntil,
        'access_token' => $token,
    ]);
    if ($res->successful()) {
        $data = $res->json('data.0.values') ?? [];
        $sum = 0;
        foreach ($data as $v) {
            $sum += (int)($v['value'] ?? 0);
        }
        echo "Metric '{$metric}' 30-Day Sum: {$sum} (Count: " . count($data) . " days)\n";
    } else {
        echo "Metric '{$metric}' Error: " . json_encode($res->json('error.message')) . "\n";
    }
}

echo "\n--- TEST 3: User Profile / Professional Dashboard Insights Check ---\n";
$meRes = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/me", [
    'fields' => 'id,name',
    'access_token' => $userToken,
]);
echo "User Account: " . json_encode($meRes->json()) . "\n";

$meInsights = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/me/insights", [
    'metric' => 'page_views_total,page_posts_impressions_organic',
    'access_token' => $userToken,
]);
echo "User /me/insights: " . json_encode($meInsights->json()) . "\n";

echo "\n--- TEST 4: Published Posts Fields (Video views / Impressions on individual posts) ---\n";
$postsRes = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}/published_posts", [
    'fields' => 'id,message,created_time,shares,likes.summary(true),comments.summary(true)',
    'limit' => 25,
    'access_token' => $token,
]);
echo "Published posts count: " . count($postsRes->json('data') ?? []) . "\n";
