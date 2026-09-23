<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$fbPage = \App\Models\FacebookPage::where('workspace_id', 9)->where('token_status', 'valid')->first();
$token = $fbPage->page_access_token;
$version = 'v23.0';

$postId1 = '115864121526929_982499514861065'; // Post 1 (17 Sep)

$possibleFields = [
    'shares',
    'status_type',
    'properties',
    'permalink_url',
    'attachments',
    'likes.summary(true)',
    'comments.summary(true)',
    'reactions.summary(true)',
    'reactions.type(LIKE).summary(true)',
    'insights',
];

foreach ($possibleFields as $f) {
    $res = \Illuminate\Support\Facades\Http::withoutVerifying()
        ->timeout(10)
        ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
        ->get("https://graph.facebook.com/{$version}/{$postId1}", [
            'fields' => "id,{$f}",
            'access_token' => $token,
        ]);
    echo "Field [{$f}]: status {$res->status()} -> " . substr($res->body(), 0, 150) . "\n";
}
