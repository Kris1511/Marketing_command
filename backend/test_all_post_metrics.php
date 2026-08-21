<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$base   = "https://graph.facebook.com/v23.0";

echo "========================================================\n";
echo " TESTING ALL METRICS ON POST 1247592255107307_122108416797421701 (TARGET = 6)\n";
echo "========================================================\n\n";

$pid = '1247592255107307_122108416797421701';

$candidateMetrics = [
    'post_views',
    'post_views_organic',
    'post_views_by_type',
    'post_content_views',
    'post_media_views',
    'post_media_views_organic',
    'views',
    'view_count',
    'content_views',
    'post_impressions_organic_v2',
    'post_plays',
    'post_impressions_unique',
    'post_impressions_fan',
    'post_impressions_by_story_type_organic',
    'post_photo_views',
    'post_photo_clicks',
    'post_photo_view_count',
    'photo_views',
    'photo_view_count',
    'impressions',
    'post_clicks',
    'post_clicks_by_type',
    'post_reactions_by_type_total',
    'post_activity',
    'post_activity_by_type',
    'post_engaged_fan',
    'post_fan_reach',
];

foreach ($candidateMetrics as $m) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$pid}/insights", [
        'metric'       => $m,
        'access_token' => $token,
    ]);
    $status = $res->status();
    $data   = $res->json('data.0.values.0.value') ?? $res->json('data.0.values.0') ?? $res->json('data') ?? $res->json('error.message');
    echo sprintf("%-38s | Status: %d | Data: %s\n", $m, $status, json_encode($data));
}
