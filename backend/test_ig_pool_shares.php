<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "========================================================\n";
echo " TESTING IG POOLED SHARES EXTRACTION\n";
echo "========================================================\n\n";

$igInteg = \App\Models\Integration::where('platform', 'instagram')->where('is_connected', true)->first();
$token   = $igInteg?->refresh_token;

$graphService = new \App\Services\FacebookGraphService();
$mediaItems = $graphService->getInstagramMediaList($token, 25, true);

echo "Fetched " . count($mediaItems) . " media items:\n";

$sharesSum = 0;
$viewsSum = 0;
foreach ($mediaItems as $item) {
    $sharesSum += $item['shares_count'];
    $viewsSum += $item['views_count'];
    echo "ID: {$item['id']} | Shares: {$item['shares_count']} | Views: {$item['views_count']} | Likes: {$item['like_count']} | Comments: {$item['comments_count']}\n";
}

echo "\nTotal Instagram Shares Sum: {$sharesSum}\n";
echo "Total Instagram Views Sum: {$viewsSum}\n";
