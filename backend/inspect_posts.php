<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->boot();

use App\Models\FacebookPost;
use App\Models\FacebookPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

echo "=== FACEBOOK POSTS + MEDIA INSPECTION ===" . PHP_EOL . PHP_EOL;

// Get all published posts with their media
$posts = FacebookPost::with('media')
    ->where('status', 'published')
    ->orderByRaw('COALESCE(published_at, created_at) DESC')
    ->take(15)
    ->get();

echo "Total published posts (latest 15): " . $posts->count() . PHP_EOL . PHP_EOL;

foreach ($posts as $p) {
    echo "─────────────────────────────────────" . PHP_EOL;
    echo "DB id         : " . $p->id . PHP_EOL;
    echo "fb_post_id    : " . ($p->fb_post_id ?? 'NULL') . PHP_EOL;
    echo "post_type     : " . ($p->post_type ?? 'NULL') . PHP_EOL;
    echo "status        : " . ($p->status ?? 'NULL') . PHP_EOL;
    echo "published_at  : " . ($p->published_at ?? 'NULL') . PHP_EOL;
    echo "link_url      : " . ($p->link_url ?? 'NULL') . PHP_EOL;
    echo "media count   : " . $p->media->count() . PHP_EOL;

    // Check media relationship
    if ($p->media->count() > 0) {
        foreach ($p->media as $m) {
            echo "  [media] id=" . $m->id
               . " | type=" . ($m->media_type ?? 'NULL')
               . " | file_url=" . ($m->file_url ? substr($m->file_url, 0, 80) . (strlen($m->file_url) > 80 ? '...' : '') : 'NULL')
               . " | file_path=" . ($m->file_path ?? 'NULL')
               . " | fb_media_id=" . ($m->fb_media_id ?? 'NULL')
               . PHP_EOL;
        }
    } else {
        echo "  [media] NONE" . PHP_EOL;
    }

    // Check cache for fb_post_pic
    $cacheKey = "fb_post_pic_{$p->fb_post_id}";
    $cached = Cache::get($cacheKey);
    echo "  cache(fb_post_pic): " . ($cached ? substr($cached, 0, 80) : 'NULL/MISS') . PHP_EOL;

    // Simulate what the API does for image_url
    $mediaFirst = $p->media->first();
    $imageUrl = $mediaFirst ? $mediaFirst->file_url : (!empty($p->link_url) ? $p->link_url : null);
    if (!$imageUrl && !empty($p->fb_post_id)) {
        $imageUrl = Cache::get($cacheKey);
    }
    echo "  → image_url (API response): " . ($imageUrl ? substr($imageUrl, 0, 100) : 'NULL') . PHP_EOL;
}

echo PHP_EOL . "=== SUMMARY BY POST TYPE ===" . PHP_EOL;
$byType = FacebookPost::where('status', 'published')
    ->selectRaw("post_type, COUNT(*) as cnt")
    ->groupBy('post_type')
    ->get();
foreach ($byType as $row) {
    echo "  " . ($row->post_type ?? 'NULL') . " => " . $row->cnt . " posts" . PHP_EOL;
}

echo PHP_EOL . "=== POSTS WITH NO MEDIA AND NO LINK_URL (Photo/Carousel types) ===" . PHP_EOL;
$noMediaPosts = FacebookPost::with('media')
    ->where('status', 'published')
    ->whereIn('post_type', ['single_image', 'multi_image', null])
    ->get()
    ->filter(function ($p) {
        return $p->media->count() === 0 && empty($p->link_url);
    });
echo "Count: " . $noMediaPosts->count() . PHP_EOL;
foreach ($noMediaPosts->take(5) as $p) {
    $cached = !empty($p->fb_post_id) ? Cache::get("fb_post_pic_{$p->fb_post_id}") : null;
    echo "  id=" . $p->id . " fb_post_id=" . ($p->fb_post_id ?? 'NULL')
       . " post_type=" . ($p->post_type ?? 'NULL')
       . " cache_hit=" . ($cached ? 'YES' : 'NO') . PHP_EOL;
}

echo PHP_EOL . "=== FACEBOOK_POST_MEDIA TABLE OVERVIEW ===" . PHP_EOL;
$totalMedia = DB::table('facebook_post_media')->count();
$mediaWithUrl = DB::table('facebook_post_media')->whereNotNull('file_url')->where('file_url', '!=', '')->count();
$mediaNoUrl = DB::table('facebook_post_media')->where(function ($q) { $q->whereNull('file_url')->orWhere('file_url', ''); })->count();
echo "Total rows: " . $totalMedia . PHP_EOL;
echo "With file_url: " . $mediaWithUrl . PHP_EOL;
echo "Without file_url: " . $mediaNoUrl . PHP_EOL;

// Sample a few media rows
$sampleMedia = DB::table('facebook_post_media')->take(5)->get();
echo PHP_EOL . "Sample media rows:" . PHP_EOL;
foreach ($sampleMedia as $sm) {
    echo "  id=" . $sm->id
       . " post_id=" . $sm->facebook_post_id
       . " type=" . ($sm->media_type ?? 'NULL')
       . " file_url=" . ($sm->file_url ? substr($sm->file_url, 0, 80) : 'NULL')
       . " fb_media_id=" . ($sm->fb_media_id ?? 'NULL')
       . PHP_EOL;
}

echo PHP_EOL . "Done." . PHP_EOL;
