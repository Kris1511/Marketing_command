<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\FacebookPage;

$page = FacebookPage::where('workspace_id', 9)->where('page_id', '115864121526929')->first();
$token = $page->page_access_token;

$postIds = [
    '115864121526929_974838228960527', // Sep 8
    '115864121526929_973082619136088', // Sep 6
    '115864121526929_972252245885792', // Sep 5 (Video / Reel)
];

$metricsToTest = [
    'post_media_view',
    'post_impressions',
    'post_impressions_unique',
    'post_video_views',
    'post_video_views_unique',
    'post_reactions_by_type_total',
    'post_engaged_users',
    'post_clicks',
    'post_activity_by_action_type',
];

foreach ($postIds as $pId) {
    echo "========================================\n";
    echo "POST: {$pId}\n";
    echo "========================================\n";
    
    // Check post details
    $pRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pId}", [
        'fields' => 'id,message,created_time,shares,reactions.summary(true),comments.summary(true),attachments{media_type,type,media}',
        'access_token' => $token,
    ]);
    echo "Post Details: " . json_encode($pRes->json('attachments') ?? []) . "\n";
    echo "Reactions: " . json_encode($pRes->json('reactions.summary') ?? []) . "\n";
    
    // Test each insight metric
    foreach ($metricsToTest as $m) {
        $insRes = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pId}/insights", [
            'metric' => $m,
            'access_token' => $token,
        ]);
        
        $status = $insRes->status();
        $data = $insRes->json('data.0') ?? [];
        $val = $data['values'][0]['value'] ?? null;
        $title = $data['title'] ?? '';
        
        if ($status === 200 && !empty($data)) {
            echo sprintf("  %-30s : %s (%s)\n", $m, json_encode($val), $title);
        } elseif ($status !== 200) {
            $err = $insRes->json('error.message') ?? 'Error';
            echo sprintf("  %-30s : [HTTP %d] %s\n", $m, $status, $err);
        } else {
            echo sprintf("  %-30s : [EMPTY data: []]\n", $m);
        }
    }
    echo "\n";
}
