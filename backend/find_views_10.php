<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$fbPage = \App\Models\FacebookPage::first();
$pageId = $fbPage->page_id;
$token  = $fbPage->page_access_token;
$base   = "https://graph.facebook.com/v23.0";

$userInteg = \App\Models\Integration::where('platform', 'facebook')->where('is_connected', true)->first();
$userToken = $userInteg?->refresh_token ?? $token;

$postId  = '1247592255107307_122108416797421701';
$photoId = '122108416797421701';

echo "===============================================================\n";
echo " DEEP INVESTIGATION FOR VIEWS = 10 ON CONTENT ID 122108416797421701\n";
echo "===============================================================\n\n";

// 1. Direct photo node query with Page Token & User Token
echo "--- 1. Photo Node {$photoId} Fields Check ---\n";
$photoFields = [
    'id', 'created_time', 'name', 'event', 'icon', 'images', 'link', 'name_tags',
    'picture', 'place', 'target', 'updated_time', 'webp_images',
    'likes.summary(true)', 'comments.summary(true)', 'reactions.summary(true)',
    'views', 'view_count', 'impressions', 'reach'
];

foreach ($photoFields as $f) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$photoId}", [
        'fields'       => $f,
        'access_token' => $token,
    ]);
    if ($res->successful()) {
        echo "  [TOKEN=PAGE] Photo Field '{$f}': " . json_encode($res->json()) . "\n";
    } else {
        echo "  [TOKEN=PAGE] Photo Field '{$f}' Error: " . json_encode($res->json('error.message')) . "\n";
    }
}

// 2. Photo node insights
echo "\n--- 2. Photo Node {$photoId}/insights Metrics Check ---\n";
$photoMetrics = [
    'views', 'view_count', 'content_views', 'impressions', 'impressions_unique',
    'post_impressions', 'post_impressions_unique', 'post_views', 'post_clicks',
    'post_reactions_by_type_total', 'photo_views', 'media_views'
];

foreach ($photoMetrics as $m) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$photoId}/insights", [
        'metric'       => $m,
        'access_token' => $token,
    ]);
    if ($res->successful()) {
        echo "  Photo Metric '{$m}': " . json_encode($res->json('data')) . "\n";
    } else {
        echo "  Photo Metric '{$m}' Error: " . json_encode($res->json('error.message')) . "\n";
    }
}

// 3. User Token on Photo Node
echo "\n--- 3. Photo Node with USER TOKEN ---\n";
$uPhotoRes = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$photoId}", [
    'fields'       => 'id,views,view_count,impressions,likes.summary(true),comments.summary(true)',
    'access_token' => $userToken,
]);
echo "User Token Photo Node: " . json_encode($uPhotoRes->json()) . "\n";

// 4. Try Graph API endpoints corresponding to Business Suite Object Insights
echo "\n--- 4. Object Insights & Business Manager Endpoints ---\n";
$endpointsToTry = [
    "{$base}/{$photoId}/insights",
    "{$base}/{$postId}/insights",
    "{$base}/{$pageId}/insights",
    "{$base}/{$photoId}/insights/views",
    "{$base}/{$postId}/insights/views",
];

foreach ($endpointsToTry as $ep) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()->get($ep, [
        'access_token' => $token,
    ]);
    echo "Endpoint '{$ep}': " . json_encode($res->json()) . "\n";
}

// 5. Query /published_posts with expanded insights metrics list
echo "\n--- 5. Test published_posts with expanded fields ---\n";
$resPub = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$base}/{$pageId}/published_posts", [
    'fields'       => 'id,message,created_time,shares,likes.summary(true),comments.summary(true),insights',
    'limit'        => 5,
    'access_token' => $token,
]);
echo "Published posts insights: " . json_encode($resPub->json('data.0.insights'), JSON_PRETTY_PRINT) . "\n";
