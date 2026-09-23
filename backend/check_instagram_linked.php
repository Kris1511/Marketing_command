<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\FacebookPage;

$page = FacebookPage::where('workspace_id', 9)->where('page_id', '115864121526929')->first();
$token = $page->page_access_token;
$pageId = $page->page_id;

$res = Http::withoutVerifying()->get("https://graph.facebook.com/v23.0/{$pageId}", [
    'fields' => 'instagram_business_account{id,username,name}',
    'access_token' => $token,
]);
echo "Instagram linked account: " . $res->body() . "\n";
