<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$postId1 = '115864121526929_982499514861065'; // Post 1 (17 Sep)
$postId3 = '115864121526929_977067722070911'; // Post 3 (11 Sep)

$since1 = strtotime('2026-09-17 00:00:00 UTC');
$until1 = strtotime('2026-09-18 23:59:59 UTC');

$r1 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId1}/insights", [
        'metric'       => 'post_media_view',
        'since'        => $since1,
        'until'        => $until1,
        'access_token' => $token,
    ]);
echo "Post 1 with since/until: " . $r1->status() . " -> " . $r1->body() . "\n";

$since3 = strtotime('2026-09-11 00:00:00 UTC');
$until3 = strtotime('2026-09-18 23:59:59 UTC');

$r3 = \Illuminate\Support\Facades\Http::withoutVerifying()
    ->timeout(10)
    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
    ->get("https://graph.facebook.com/{$version}/{$postId3}/insights", [
        'metric'       => 'post_media_view',
        'since'        => $since3,
        'until'        => $until3,
        'access_token' => $token,
    ]);
echo "Post 3 with since/until: " . $r3->status() . " -> " . $r3->body() . "\n";
