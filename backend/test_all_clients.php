<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Integration;
use Illuminate\Support\Facades\Http;

$clients = [
    'GOOGLE_CLIENT (776969033810)' => [
        'id' => env('GOOGLE_CLIENT_ID'),
        'secret' => env('GOOGLE_CLIENT_SECRET')
    ],
    'GA_CLIENT (590753550603)' => [
        'id' => env('GOOGLE_ANALYTICS_CLIENT_ID'),
        'secret' => env('GOOGLE_ANALYTICS_CLIENT_SECRET')
    ],
    'GSC_CLIENT (979638261972)' => [
        'id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID'),
        'secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET')
    ]
];

$integrations = Integration::whereIn('platform', ['google_analytics', 'search_console', 'youtube'])
    ->whereNotNull('refresh_token')
    ->get();

foreach ($integrations as $integ) {
    echo "\nTesting token for platform: {$integ->platform} (WS {$integ->workspace_id}, Account: {$integ->account_id})\n";
    foreach ($clients as $clientName => $client) {
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $client['id'],
            'client_secret' => $client['secret'],
            'refresh_token' => $integ->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        echo "  With {$clientName}: Status " . $res->status() . " -> " . ($res->successful() ? "SUCCESS! Access Token received!" : $res->json('error_description') ?? $res->json('error')) . "\n";
    }
}
