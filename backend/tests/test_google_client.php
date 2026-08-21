<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new App\Services\YouTubeService();
$url = $service->getAuthUrl('test_state_123');

echo "SUCCESS: Google Client resolved and YouTubeService working properly!\n";
echo "OAuth URL starts with: " . substr($url, 0, 45) . "...\n";
