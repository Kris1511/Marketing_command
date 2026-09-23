<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\FacebookPost;
use App\Models\FacebookPage;

class InspectFacebookPosts extends Command
{
    protected $signature = 'inspect:fb-posts';
    protected $description = 'Inspect Facebook posts and media image data returned by the backend API';

    public function handle()
    {
        $this->info('=== FACEBOOK POSTS + MEDIA INSPECTION ===');
        $this->newLine();

        // ── 1. Database summary by post type ──────────────────────────────────
        $this->info('--- Post type distribution (all workspaces) ---');
        $byType = DB::table('facebook_posts')
            ->select(DB::raw('COALESCE(post_type, "NULL") as post_type'), DB::raw('COUNT(*) as cnt'))
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->groupBy('post_type')
            ->get();
        $headers = ['post_type', 'count'];
        $this->table($headers, $byType->map(fn($r) => [(string)$r->post_type, $r->cnt])->toArray());

        // ── 2. facebook_post_media table overview ─────────────────────────────
        $this->info('--- facebook_post_media table overview ---');
        $totalMedia    = DB::table('facebook_post_media')->count();
        $withUrl       = DB::table('facebook_post_media')->whereNotNull('file_url')->where('file_url', '!=', '')->count();
        $withPath      = DB::table('facebook_post_media')->whereNotNull('file_path')->where('file_path', '!=', '')->count();
        $withFbMediaId = DB::table('facebook_post_media')->whereNotNull('fb_media_id')->where('fb_media_id', '!=', '')->count();
        $noUrl         = $totalMedia - $withUrl;

        $this->table(['metric', 'value'], [
            ['Total rows in facebook_post_media', $totalMedia],
            ['Rows with file_url set', $withUrl],
            ['Rows without file_url (NULL/empty)', $noUrl],
            ['Rows with file_path set', $withPath],
            ['Rows with fb_media_id set', $withFbMediaId],
        ]);

        // ── 3. Sample raw rows from facebook_post_media ───────────────────────
        $this->info('--- Sample rows from facebook_post_media (latest 10) ---');
        $sampleMedia = DB::table('facebook_post_media')
            ->orderBy('id', 'desc')
            ->take(10)
            ->get(['id', 'facebook_post_id', 'media_type', 'file_url', 'file_path', 'fb_media_id', 'sort_order']);

        $mediaHeaders = ['id', 'post_id', 'type', 'file_url (first 70 chars)', 'file_path', 'fb_media_id'];
        $this->table($mediaHeaders, $sampleMedia->map(fn($m) => [
            $m->id,
            $m->facebook_post_id,
            $m->media_type ?? 'NULL',
            $m->file_url ? substr($m->file_url, 0, 70) : 'NULL',
            $m->file_path ?? 'NULL',
            $m->fb_media_id ?? 'NULL',
        ])->toArray());

        // ── 4. Latest 15 published posts with their media count ───────────────
        $this->info('--- Latest 15 published posts + media relationship ---');
        $posts = FacebookPost::with('media')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->take(15)
            ->get();

        $this->info('Total shown: ' . $posts->count());
        $postHeaders = ['DB id', 'fb_post_id', 'post_type', 'media_count', 'link_url (60)', 'cache_hit', 'API image_url (80)'];
        $postRows = $posts->map(function ($p) {
            $cacheKey = "fb_post_pic_{$p->fb_post_id}";
            $cached   = !empty($p->fb_post_id) ? Cache::get($cacheKey) : null;

            // Simulate what the API does
            $mediaFirst = $p->media->first();
            $imageUrl   = $mediaFirst ? $mediaFirst->file_url
                        : (!empty($p->link_url) ? $p->link_url : null);
            if (!$imageUrl && !empty($p->fb_post_id)) {
                $imageUrl = Cache::get($cacheKey);
            }

            return [
                $p->id,
                $p->fb_post_id ?? 'NULL',
                $p->post_type ?? 'NULL',
                $p->media->count(),
                $p->link_url ? substr($p->link_url, 0, 60) : 'NULL',
                $cached ? 'YES' : 'NO',
                $imageUrl ? substr($imageUrl, 0, 80) : 'NULL',
            ];
        })->toArray();
        $this->table($postHeaders, $postRows);

        // ── 5. Posts missing image data (the real problem check) ─────────────
        $this->info('--- Posts with NULL image_url in simulated API response ---');
        $nullImagePosts = $posts->filter(function ($p) {
            $cacheKey = "fb_post_pic_{$p->fb_post_id}";
            $cached   = !empty($p->fb_post_id) ? Cache::get($cacheKey) : null;
            $mediaFirst = $p->media->first();
            $imageUrl = $mediaFirst ? $mediaFirst->file_url
                      : (!empty($p->link_url) ? $p->link_url : null);
            if (!$imageUrl && !empty($p->fb_post_id)) {
                $imageUrl = $cached;
            }
            return $imageUrl === null;
        });

        $this->warn('Posts with NULL image_url: ' . $nullImagePosts->count() . ' out of ' . $posts->count());
        foreach ($nullImagePosts as $p) {
            $this->line("  → id={$p->id} fb_post_id=" . ($p->fb_post_id ?? 'NULL') . " post_type=" . ($p->post_type ?? 'NULL') . " media_count=" . $p->media->count());
        }

        // ── 6. Breakdown: photo/carousel posts specifically ───────────────────
        $this->newLine();
        $this->info('--- Photo / Carousel / single_image / multi_image posts specifically ---');
        $photoPosts = FacebookPost::with('media')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereIn('post_type', ['single_image', 'multi_image'])
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->take(20)
            ->get();

        $this->info('Photo/Carousel posts (latest 20): ' . $photoPosts->count());
        $photoHeaders = ['id', 'fb_post_id', 'type', 'media_count', 'media.file_url (first 80)', 'link_url', 'image_url resolved'];
        $photoRows = $photoPosts->map(function ($p) {
            $cacheKey   = "fb_post_pic_{$p->fb_post_id}";
            $cached     = !empty($p->fb_post_id) ? Cache::get($cacheKey) : null;
            $mediaFirst = $p->media->first();
            $imageUrl   = $mediaFirst ? $mediaFirst->file_url
                        : (!empty($p->link_url) ? $p->link_url : null);
            if (!$imageUrl && !empty($p->fb_post_id)) {
                $imageUrl = $cached;
            }
            return [
                $p->id,
                $p->fb_post_id ?? 'NULL',
                $p->post_type ?? 'NULL',
                $p->media->count(),
                $mediaFirst && $mediaFirst->file_url ? substr($mediaFirst->file_url, 0, 80) : 'NULL',
                $p->link_url ? substr($p->link_url, 0, 40) : 'NULL',
                $imageUrl ? '✓ ' . substr($imageUrl, 0, 50) : 'NULL ← MISSING',
            ];
        })->toArray();
        $this->table($photoHeaders, $photoRows);

        // ── 7. Check fb_media_id: are Media IDs stored for Graph API lookups? ─
        $this->newLine();
        $this->info('--- fb_media_id presence per post type (can media thumbnails be fetched?) ---');
        $fbMediaStats = DB::table('facebook_post_media as fpm')
            ->join('facebook_posts as fp', 'fp.id', '=', 'fpm.facebook_post_id')
            ->select(
                DB::raw('fp.post_type'),
                DB::raw('COUNT(*) as total_media_rows'),
                DB::raw('SUM(CASE WHEN fpm.fb_media_id IS NOT NULL AND fpm.fb_media_id != "" THEN 1 ELSE 0 END) as with_fb_media_id'),
                DB::raw('SUM(CASE WHEN fpm.file_url IS NOT NULL AND fpm.file_url != "" THEN 1 ELSE 0 END) as with_file_url')
            )
            ->where('fp.status', 'published')
            ->whereNull('fp.deleted_at')
            ->groupBy('fp.post_type')
            ->get();
        $this->table(['post_type', 'total_media_rows', 'with_fb_media_id', 'with_file_url'], $fbMediaStats->map(fn($r) => [
            $r->post_type ?? 'NULL', $r->total_media_rows, $r->with_fb_media_id, $r->with_file_url,
        ])->toArray());

        // ── 8. Live API response check (call the real endpoint) ───────────────
        $this->newLine();
        $this->info('--- Live API call to /api/facebook/posts ---');
        // Find a workspace with a connected FB page
        $fbPage = \App\Models\FacebookPage::whereNotNull('page_access_token')
            ->where('token_status', 'valid')
            ->first();

        if (!$fbPage) {
            $this->warn('No connected Facebook page found — skipping live API call.');
        } else {
            $workspaceId = $fbPage->workspace_id;
            $this->line("Using workspace_id={$workspaceId}, page={$fbPage->page_name}");
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Accept' => 'application/json',
                ])->timeout(30)->get('http://127.0.0.1:8000/api/facebook/posts', [
                    'workspace_id' => $workspaceId,
                    'page'         => 1,
                    'per_page'     => 5,
                    'all_posts'    => 'true',
                ]);

                if ($response->successful()) {
                    $body = $response->json();
                    $this->info('API returned success: ' . ($body['success'] ? 'true' : 'false'));
                    $this->info('Total posts: ' . ($body['total'] ?? '?'));
                    $posts_data = $body['data'] ?? [];
                    $this->info('Posts in this page: ' . count($posts_data));
                    $this->newLine();

                    $apiHeaders = ['id', 'fb_post_id', 'post_type', 'formatted_type', 'media_count', 'image_url (100 chars)', 'likes', 'comments'];
                    $apiRows = array_map(fn($p) => [
                        $p['id'],
                        $p['fb_post_id'] ?? 'NULL',
                        $p['post_type'] ?? 'NULL',
                        $p['formatted_type'] ?? 'NULL',
                        $p['media_count'] ?? 0,
                        isset($p['image_url']) && $p['image_url'] ? substr($p['image_url'], 0, 100) : 'NULL ← MISSING',
                        $p['likes_count'] ?? 0,
                        $p['comments_count'] ?? 0,
                    ], $posts_data);
                    $this->table($apiHeaders, $apiRows);

                    // Count missing images
                    $missingImg = array_filter($posts_data, fn($p) => empty($p['image_url']));
                    $this->warn('Posts with null/empty image_url in API response: ' . count($missingImg) . ' / ' . count($posts_data));
                    foreach ($missingImg as $p) {
                        $this->line("  → id={$p['id']} fb_post_id=" . ($p['fb_post_id'] ?? 'NULL') . " type=" . ($p['post_type'] ?? 'NULL') . " formatted=" . ($p['formatted_type'] ?? '?'));
                    }
                } else {
                    $this->error('API call failed: HTTP ' . $response->status());
                    $this->line($response->body());
                }
            } catch (\Throwable $e) {
                $this->error('HTTP call exception: ' . $e->getMessage());
                $this->warn('Note: The API requires auth tokens. Try adding a Bearer token if 401.');
            }
        }

        $this->newLine();
        $this->info('=== INSPECTION COMPLETE ===');
        return 0;
    }
}
