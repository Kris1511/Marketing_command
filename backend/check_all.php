<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "WORKSPACES:\n";
print_r(App\Models\Workspace::all()->toArray());

echo "\nINTEGRATIONS:\n";
print_r(App\Models\Integration::all()->toArray());
