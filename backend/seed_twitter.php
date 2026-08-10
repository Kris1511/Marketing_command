<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Integration;

$integration = Integration::updateOrCreate(
    [
        'workspace_id' => 1,
        'platform'     => 'twitter',
        'account_id'   => 'twitter_demo_101',
    ],
    [
        'account_name'            => '@DemoAgency_X',
        'refresh_token'           => 'mock_refresh_token_123',
        'access_token_expires_at' => now()->addDays(30),
        'token_expires_at'        => now()->addDays(30),
        'is_connected'            => true,
        'connection_status'       => 'connected',
        'last_sync_at'            => now(),
    ]
);

echo "Integration saved successfully:\n";
echo json_encode($integration, JSON_PRETTY_PRINT);
