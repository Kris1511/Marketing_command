<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$integrations = App\Models\Integration::all();
echo json_encode($integrations, JSON_PRETTY_PRINT);
