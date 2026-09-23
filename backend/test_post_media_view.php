<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$posts = [
    'Post 1' => '115864121526929_982499514861065',
    'Post 2' => '115864121526929_978150271962656',
    'Post 3' => '115864121526929_977067722070911',
];

foreach ($posts as $name => $postId) {
    echo "=== {$name} ({$postId}) ===\n";
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()
        ->timeout(10)
        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
        ->get("https://graph.facebook.com/{$version}/{$postId}/insights", [
            'metric'       => 'post_media_view',
            'period'       => 'lifetime',
            'access_token' => $token,
        ]);
    echo "Status: " . $res->status() . "\n";
    echo "Body: " . $res->body() . "\n";
}
