<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$conn = App\Models\YouTubeConnection::where('workspace_id', 9)->first();
if (!$conn) {
    echo "No YouTube connection for workspace 9\n";
    exit;
}

echo "Channel Name: {$conn->channel_name} ({$conn->channel_id})\n";
echo "Access Token: " . (!empty($conn->access_token) ? "YES (len " . strlen($conn->access_token) . ")" : "NO") . "\n";
echo "Refresh Token: " . (!empty($conn->refresh_token) ? "YES (len " . strlen($conn->refresh_token) . ")" : "NO") . "\n";
echo "Expires At: {$conn->token_expires_at}\n";
echo "Is Expired: " . ($conn->token_expires_at && $conn->token_expires_at->isPast() ? "YES" : "NO") . "\n\n";

$service = new App\Services\YouTubeService();

echo "--- Testing refreshAccessTokenIfNeeded ---\n";
try {
    $refreshed = $service->refreshAccessTokenIfNeeded($conn);
    echo "Refresh Success! New expires_at: {$refreshed->token_expires_at}\n";
} catch (\Throwable $e) {
    echo "Refresh FAILED: " . $e->getMessage() . "\n";
}

echo "\n--- Testing getAnalyticsOverview ---\n";
try {
    $overview = $service->getAnalyticsOverview($conn, '2026-06-01', '2026-09-10', true);
    echo "Overview Success: " . ($overview['success'] ? 'YES' : 'NO') . "\n";
    echo "Overview Error Type: " . ($overview['error_type'] ?? 'none') . "\n";
    echo "Overview Error Message: " . ($overview['error_message'] ?? 'none') . "\n";
    echo "Overview Views: " . var_export($overview['views'] ?? null, true) . "\n";
    echo "Overview Likes: " . var_export($overview['likes'] ?? null, true) . "\n";
    echo "Overview Comments: " . var_export($overview['comments'] ?? null, true) . "\n";
    echo "Overview Shares: " . var_export($overview['shares'] ?? null, true) . "\n";
} catch (\Throwable $e) {
    echo "Overview FAILED: " . $e->getMessage() . "\n";
}
