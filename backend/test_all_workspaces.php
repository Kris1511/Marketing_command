<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$workspaces = \App\Models\Workspace::all();
foreach ($workspaces as $w) {
    echo "========================================\n";
    echo " WORKSPACE ID {$w->id}: {$w->name}\n";
    echo "========================================\n";
    $request = Illuminate\Http\Request::create('/api/v1/dashboard/overview', 'GET', ['workspace_id' => $w->id, 'days' => 30]);
    app()->instance('request', $request);
    try {
        $route = $app['router']->getRoutes()->match($request);
        $res = $route->run();
        $data = json_decode($res->getContent(), true)['data'] ?? [];
        echo "FB Metrics: " . json_encode($data['facebook_metrics'] ?? []) . "\n";
        echo "Connected Page: " . json_encode($data['connected_page'] ?? []) . "\n\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
