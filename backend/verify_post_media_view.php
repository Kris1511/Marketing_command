<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$base   = "https://graph.facebook.com/v23.0";

echo "=== TESTING METRIC 'post_media_view' ACROSS ALL POSTS ===\n\n";

$targetPosts = [
    '1247592255107307_122108416797421701' => 10, // Aug 14 post
    '1247592255107307_122107713759421701' => 4,  // Aug 12 post 1
    '1247592255107307_122107713027421701' => 11, // Aug 12 post 2
];

foreach ($targetPosts as $pid => $expectedMbsViews) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$pid}/insights", [
        'metric'       => 'post_media_view',
        'access_token' => $token,
    ]);
    
    $val = $res->json('data.0.values.0.value');
    echo sprintf("Post %-35s | Meta Business Suite Views: %-2d | API post_media_view: %s | Match: %s\n",
        $pid,
        $expectedMbsViews,
        json_encode($val),
        ($val == $expectedMbsViews ? 'EXACT MATCH ✓' : 'DIFFERENT')
    );
}
