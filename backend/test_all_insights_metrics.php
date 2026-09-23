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

$since = 1788332400; // 2026-09-02 07:00:00 UTC
$until = 1788937200; // 2026-09-09 07:00:00 UTC

$metrics = [
    // Media & Views
    'page_media_view',
    'page_total_media_view_unique',
    'page_views_total',
    'page_views_logout',
    'page_views_logged_in_total',
    'page_views_logged_in_unique',
    // Video Views
    'page_video_views',
    'page_video_views_paid',
    'page_video_views_organic',
    'page_video_views_by_paid_non_paid',
    'page_video_views_autoplayed',
    'page_video_views_click_to_play',
    'page_video_views_unique',
    'page_video_repeat_views',
    'page_video_complete_views_30s',
    'page_video_complete_views_30s_unique',
    'page_video_view_time',
    // Impressions & Reach
    'page_posts_impressions',
    'page_posts_impressions_unique',
    'page_posts_impressions_paid',
    'page_posts_impressions_organic',
    // Engagement
    'page_post_engagements',
    'page_daily_follows',
    'page_actions_post_reactions_total',
    'page_consumptions',
    'page_consumptions_unique',
];

echo "=== TESTING ALL KNOWN PAGE INSIGHTS METRICS ON META GRAPH API v23.0 ===\n";
echo "Page ID: {$pageId}\n";
echo "Date Range: 2026-09-02 to 2026-09-09\n\n";

$results = [];

foreach ($metrics as $m) {
    foreach (['day', 'week', 'days_28'] as $period) {
        $url = "https://graph.facebook.com/v23.0/{$pageId}/insights";
        $res = Http::withoutVerifying()->get($url, [
            'metric' => $m,
            'period' => $period,
            'since'  => $since,
            'until'  => $until,
            'access_token' => $token,
        ]);
        
        $status = $res->status();
        $data = $res->json('data') ?? [];
        
        if ($status === 200 && !empty($data)) {
            $valSum = 0;
            $allValues = [];
            foreach ($data[0]['values'] ?? [] as $v) {
                $valSum += is_numeric($v['value'] ?? 0) ? $v['value'] : 0;
                $allValues[] = $v['value'] ?? null;
            }
            echo sprintf("[FOUND DATA! ✓] Metric: %-32s | Period: %-7s | Sum: %d | Values: %s\n",
                $m, $period, $valSum, json_encode($allValues)
            );
            $results[] = [
                'metric' => $m,
                'period' => $period,
                'sum' => $valSum,
                'values' => $allValues
            ];
            break; // found for this metric
        } elseif ($status === 200 && empty($data)) {
            // empty data: []
            // continue checking other periods
        } else {
            // error (invalid metric for this period or deprecated)
            break;
        }
    }
}

if (empty($results)) {
    echo "\n>>> ALL tested page insights metrics returned data: [] (empty) or 400 from Meta.\n";
} else {
    echo "\n>>> METRICS WITH DATA:\n";
    print_r($results);
}
