<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\FacebookPage;

$page = FacebookPage::where('workspace_id', 9)->where('page_id', '115864121526929')->first();
$token = $page->page_access_token;

$since = 1788332400; // 2026-09-02 07:00:00 UTC
$until = 1788937200; // 2026-09-09 07:00:00 UTC
$igId = '17841447923134069';

$res = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$igId}/insights", [
    'metric' => 'reach',
    'metric_type' => 'total_value',
    'period' => 'day',
    'since' => $since,
    'until' => $until,
    'access_token' => $token,
]);

echo "IG insights (reach,impressions,views):\n" . $res->body() . "\n";
