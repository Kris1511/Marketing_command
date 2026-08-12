<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "========================================================\n";
echo " TESTING ALL IG MEDIA FIELDS FOR SHARES & RESHARES\n";
echo "========================================================\n\n";

$igInteg = \App\Models\Integration::where('platform', 'instagram')->where('is_connected', true)->first();
$token   = $igInteg?->refresh_token;
$isIgToken = str_starts_with($token, 'IGAA');
$baseUrl   = $isIgToken ? 'https://graph.instagram.com/v23.0' : "https://graph.facebook.com/v23.0";

$res = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$baseUrl}/me/media", [
    'fields' => 'id,caption,media_type,like_count,comments_count,shares',
    'limit' => 10,
    'access_token' => $token,
]);

echo "Response for /me/media with fields=shares:\n";
echo json_encode($res->json(), JSON_PRETTY_PRINT) . "\n\n";

$items = $res->json('data') ?? [];
foreach (array_slice($items, 0, 3) as $item) {
    $mId = $item['id'];
    $insRes = \Illuminate\Support\Facades\Http::withoutVerifying()->get("{$baseUrl}/{$mId}/insights", [
        'metric' => 'shares,reshares,total_interactions,ig_reels_aggregated_all_plays_count',
        'access_token' => $token,
    ]);
    echo "Insights for {$mId}:\n";
    echo json_encode($insRes->json(), JSON_PRETTY_PRINT) . "\n";
}
