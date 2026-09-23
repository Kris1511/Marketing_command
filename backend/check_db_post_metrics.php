<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$posts = App\Models\FacebookPost::where('workspace_id', 9)->get();
echo "Total posts in DB for WS 9: " . count($posts) . "\n";

$sumViews = 0;
$sumLikes = 0;
$sumReach = 0;
$sumImpr = 0;

foreach ($posts as $p) {
    $views = $p->views_count ?? 0;
    $sumViews += $views;
    $sumLikes += ($p->likes_count ?? 0);
    $sumReach += ($p->reach_count ?? 0);
    $sumImpr += ($p->impressions_count ?? 0);
    if ($views > 0 || ($p->likes_count ?? 0) > 0) {
        echo "Post {$p->id} ({$p->published_at}): views={$views}, likes={$p->likes_count}, reach={$p->reach_count}\n";
    }
}

echo "\nAll-time Sum of views_count: {$sumViews}\n";
echo "All-time Sum of likes_count: {$sumLikes}\n";
echo "All-time Sum of reach_count: {$sumReach}\n";
echo "All-time Sum of impressions_count: {$sumImpr}\n";
