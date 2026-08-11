<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "========================================================\n";
echo " DEBUGGING INSTAGRAM SHARES METRIC FOR ALL MEDIA\n";
echo "========================================================\n\n";

$igInteg = \App\Models\Integration::where('platform', 'instagram')->where('is_connected', true)->first();
$token   = $igInteg?->refresh_token;

$isIgToken = str_starts_with($token, 'IGAA');
$baseUrl   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "https://graph.facebook.com/v23.0";

$res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$baseUrl}/me/media", [
    'fields' => 'id,caption,media_type,like_count,comments_count',
    'limit' => 25,
    'access_token' => $token,
]);

$items = $res->json('data') ?? [];
echo "Fetched " . count($items) . " media items:\n\n";

$totalShares = 0;
foreach ($items as $item) {
    $mId   = $item['id'];
    $mType = $item['media_type'] ?? 'IMAGE';

    // Try standard insights
    $insightsRes = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$baseUrl}/{$mId}/insights", [
        'metric' => 'shares,views,saved,reach,total_interactions',
        'access_token' => $token,
    ]);

    $shareVal = 0;
    $rawSharesData = null;

    if ($insightsRes->successful()) {
        foreach ($insightsRes->json('data') ?? [] as $m) {
            if (($m['name'] ?? '') === 'shares') {
                $rawSharesData = $m;
                $shareVal = (int)($m['values'][0]['value'] ?? 0);
            }
        }
    } else {
        // Log error body
        $rawSharesData = "ERROR: " . $insightsRes->body();
    }

    $totalShares += $shareVal;
    echo "Media ID: {$mId} | Type: {$mType} | Shares: {$shareVal}\n";
    if (!empty($rawSharesData)) {
        echo "   Raw Shares Insight: " . json_encode($rawSharesData) . "\n";
    }
}

echo "\nTotal Summed Shares: {$totalShares}\n";
