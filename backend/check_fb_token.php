<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\FacebookPage;

$page = FacebookPage::where('workspace_id', 9)
    ->where('page_id', '115864121526929')
    ->first();

if (!$page) {
    echo "No page found for workspace 9 with page_id 115864121526929\n";
    exit(1);
}

echo "=== 1. FACEBOOK PAGE STATUS IN DATABASE ===\n";
echo "Page Record ID: " . $page->id . "\n";
echo "Page Name: " . $page->page_name . "\n";
echo "Page ID: " . $page->page_id . "\n";
echo "Workspace ID: " . $page->workspace_id . "\n";
echo "Token Status: " . $page->token_status . "\n";
echo "Updated At: " . $page->updated_at . "\n";
echo "Connected Since: " . $page->connected_since . "\n\n";

$token = $page->page_access_token;
$appId = config('services.facebook.client_id');
$appSecret = config('services.facebook.client_secret');
$appToken = "{$appId}|{$appSecret}";

// 1. Debug token
$debugUrl = "https://graph.facebook.com/v23.0/debug_token?input_token={$token}&access_token={$appToken}";
$debugResp = Http::withoutVerifying()->timeout(15)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($debugUrl);
$debugData = $debugResp->json('data') ?? [];
$scopes = $debugData['scopes'] ?? [];
$isValid = $debugData['is_valid'] ?? false;

echo "=== 2. TOKEN DEBUG (debug_token) ===\n";
echo "Is Valid: " . ($isValid ? 'YES' : 'NO') . "\n";
echo "App ID: " . ($debugData['app_id'] ?? 'N/A') . "\n";
echo "Type: " . ($debugData['type'] ?? 'N/A') . "\n";
echo "Profile ID: " . ($debugData['profile_id'] ?? 'N/A') . "\n";
echo "User ID: " . ($debugData['user_id'] ?? 'N/A') . "\n";
echo "Scopes Count: " . count($scopes) . "\n";
echo "Granted Scopes: " . implode(', ', $scopes) . "\n";
$hasReadEngagement = in_array('pages_read_engagement', $scopes);
echo "pages_read_engagement present: " . ($hasReadEngagement ? 'YES ✓' : 'NO ✗') . "\n";
echo "Raw debug response: " . $debugResp->body() . "\n\n";

// 2. Query Meta Graph API v23.0 for exact date range (2026-09-02 07:00:00 UTC to 2026-09-09 07:00:00 UTC)
$since = 1788332400; // 2026-09-02 07:00:00 UTC
$until = 1788937200; // 2026-09-09 07:00:00 UTC
$pageId = $page->page_id;

echo "=== 3. RAW META GRAPH API v23.0 RESPONSES ===\n";
echo "Page ID: {$pageId}\n";
echo "Date Range: 2026-09-02 07:00:00 UTC to 2026-09-09 07:00:00 UTC (since={$since}, until={$until})\n\n";

// A. page_media_view (period=day)
$urlViews = "https://graph.facebook.com/v23.0/{$pageId}/insights?metric=page_media_view&period=day&since={$since}&until={$until}&access_token={$token}";
$resViews = Http::withoutVerifying()->timeout(15)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($urlViews);
echo "--- A. metric=page_media_view (period=day) ---\n";
echo "Status: " . $resViews->status() . "\n";
echo "Raw Response:\n" . $resViews->body() . "\n\n";

// B. page_total_media_view_unique (period=week)
$urlUnique = "https://graph.facebook.com/v23.0/{$pageId}/insights?metric=page_total_media_view_unique&period=week&since={$since}&until={$until}&access_token={$token}";
$resUnique = Http::withoutVerifying()->timeout(15)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($urlUnique);
echo "--- B. metric=page_total_media_view_unique (period=week) ---\n";
echo "Status: " . $resUnique->status() . "\n";
echo "Raw Response:\n" . $resUnique->body() . "\n\n";

// C. page_post_engagements (period=day)
$urlEngage = "https://graph.facebook.com/v23.0/{$pageId}/insights?metric=page_post_engagements&period=day&since={$since}&until={$until}&access_token={$token}";
$resEngage = Http::withoutVerifying()->timeout(15)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($urlEngage);
echo "--- C. metric=page_post_engagements (period=day) ---\n";
echo "Status: " . $resEngage->status() . "\n";
echo "Raw Response:\n" . $resEngage->body() . "\n\n";

// D. page_impressions (period=day)
$urlImpr = "https://graph.facebook.com/v23.0/{$pageId}/insights?metric=page_impressions&period=day&since={$since}&until={$until}&access_token={$token}";
$resImpr = Http::withoutVerifying()->timeout(15)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($urlImpr);
echo "--- D. metric=page_impressions (period=day) ---\n";
echo "Status: " . $resImpr->status() . "\n";
echo "Raw Response:\n" . $resImpr->body() . "\n\n";

// 4. Test backend response from /api/v1/facebook/performance-metrics
echo "=== 4. BACKEND ENDPOINT RESPONSE (/api/v1/facebook/performance-metrics) ===\n";
$req = \Illuminate\Http\Request::create('/api/v1/facebook/performance-metrics', 'GET', [
    'workspace_id' => 9,
    'start_date'   => '2026-09-02',
    'end_date'     => '2026-09-08',
    'force_refresh'=> 1,
]);
$resBackend = $app->handle($req);
echo "Status: " . $resBackend->getStatusCode() . "\n";
echo "Response Body:\n" . $resBackend->getContent() . "\n\n";

// 5. Check Posts in workspace 9 and their metrics for top summary
echo "=== 5. POSTS AND TOP SUMMARY METRICS FOR WORKSPACE 9 ===\n";
$posts = \App\Models\FacebookPost::where('workspace_id', 9)->get(['id', 'post_id', 'message', 'reach_count', 'impressions_count', 'reactions_count', 'comments_count', 'shares_count', 'status', 'published_at']);
echo "Posts Count: " . count($posts) . "\n";
foreach ($posts as $p) {
    echo "  Post {$p->id} ({$p->post_id}): Reach={$p->reach_count}, Impr={$p->impressions_count}, Reactions={$p->reactions_count}, Comments={$p->comments_count}, Shares={$p->shares_count}, Status={$p->status}, PublishedAt={$p->published_at}\n";
}
