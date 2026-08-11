<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "========================================================\n";
echo " TESTING IG METRICS WITH EXACT SUPPORTED FIELD QUERY\n";
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

$poolResponses = \Illuminate\Support\Facades\Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($items, $baseUrl, $token) {
    return array_map(function ($item) use ($pool, $baseUrl, $token) {
        return $pool->as($item['id'])->withoutVerifying()->timeout(5)->get("{$baseUrl}/{$item['id']}/insights", [
            'metric' => 'shares,views,saved,reach,total_interactions',
            'access_token' => $token,
        ]);
    }, $items);
});

$totalShares = 0;
$totalViews = 0;

foreach ($items as $item) {
    $mId = $item['id'];
    $r = $poolResponses[$mId] ?? null;
    $shares = 0;
    $views = 0;
    if ($r && $r->successful()) {
        foreach ($r->json('data') ?? [] as $m) {
            if ($m['name'] === 'shares') $shares = (int)($m['values'][0]['value'] ?? 0);
            if ($m['name'] === 'views') $views = (int)($m['values'][0]['value'] ?? 0);
        }
    } else {
        echo "FAILED FOR {$mId}: " . ($r ? $r->body() : 'NULL') . "\n";
    }
    $totalShares += $shares;
    $totalViews += $views;
    if ($shares > 0) {
        echo "--> Media ID {$mId} HAS SHARES: {$shares} (Views: {$views})\n";
    }
}

echo "\nTotal Shares Sum: {$totalShares}\n";
echo "Total Views Sum: {$totalViews}\n";
