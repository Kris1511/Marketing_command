<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$base   = "https://graph.facebook.com/v23.0";

$postId = '1247592255107307_122108416797421701'; // Target Views = 10

echo "========================================================\n";
echo " EXHAUSTIVE METRIC SCAN ON POST {$postId}\n";
echo "========================================================\n\n";

$metricsToTest = [
    'post_impressions',
    'post_impressions_unique',
    'post_impressions_paid',
    'post_impressions_paid_unique',
    'post_impressions_fan',
    'post_impressions_fan_unique',
    'post_impressions_organic',
    'post_impressions_organic_unique',
    'post_impressions_viral',
    'post_impressions_viral_unique',
    'post_impressions_nonviral',
    'post_impressions_nonviral_unique',
    'post_impressions_by_story_type',
    'post_impressions_by_story_type_organic',
    'post_impressions_by_user_country',
    'post_impressions_by_age_gender_organic',
    'post_engaged_users',
    'post_negative_feedback',
    'post_negative_feedback_by_type',
    'post_clicks',
    'post_clicks_by_type',
    'post_reactions_by_type_total',
    'post_reactions_like_total',
    'post_reactions_love_total',
    'post_reactions_wow_total',
    'post_reactions_haha_total',
    'post_reactions_sorry_total',
    'post_reactions_anger_total',
    'post_video_views',
    'post_video_views_organic',
    'post_video_views_paid',
    'post_video_views_autoplayed',
    'post_video_views_clicked_to_play',
    'post_video_views_10s',
    'post_video_views_10s_organic',
    'post_video_views_10s_paid',
    'post_video_views_10s_autoplayed',
    'post_video_views_10s_clicked_to_play',
    'post_video_views_15s',
    'post_video_views_60s_excludes_short',
    'post_video_views_by_distribution_type',
    'post_video_view_time',
    'post_video_view_time_organic',
    'post_media_view',
    'post_media_views',
    'post_views',
    'post_views_organic',
    'views',
    'view_count',
];

$matches = [];

foreach ($metricsToTest as $m) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$postId}/insights", [
        'metric'       => $m,
        'access_token' => $token,
    ]);
    
    $status = $res->status();
    $body   = $res->json();
    
    if ($status === 200) {
        $val = $body['data'][0]['values'][0]['value'] ?? null;
        $title = $body['data'][0]['title'] ?? '';
        echo sprintf("✓ [200] %-40s => %s (%s)\n", $m, json_encode($val), $title);
        if ($val === 10 || (is_array($val) && in_array(10, $val, true))) {
            $matches[] = $m;
        }
    } else {
        $msg = $body['error']['message'] ?? 'Error';
        echo sprintf("✗ [%d] %-40s => %s\n", $status, $m, $msg);
    }
}

echo "\n========================================================\n";
echo "MATCHES FOUND FOR 10 VIEWS: " . json_encode($matches) . "\n";
echo "========================================================\n";
