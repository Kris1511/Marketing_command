<?php

namespace Tests\Feature;

use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookPostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FacebookGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publishing History thumbnails: URLs rebuilt from stored files, the public media route, imported (Meta CDN)
 * images, the sync guard and the repair command.
 *
 * SAFETY: in-memory SQLite, Storage::fake, Http::fake + preventStrayRequests. Nothing is published.
 */
class PublishingHistoryThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->ws = Workspace::create(['name' => 'History Client']);
        config(['app.url' => 'https://api.example.com', 'services.public_media.url' => null]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function jpegBytes(int $w = 40, int $h = 30): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 36, 87, 230));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function storeFile(string $relative, ?string $bytes = null): void
    {
        Storage::disk('public')->put($relative, $bytes ?? $this->jpegBytes());
    }

    private function makePost(array $attrs = [], array $media = []): FacebookPost
    {
        $post = FacebookPost::create(array_merge([
            'workspace_id' => $this->ws->id,
            'post_type'    => 'single_image',
            'content'      => 'Caption',
            'status'       => 'published',
            'published_at' => now()->subDay(),
        ], $attrs));
        foreach ($media as $i => $m) {
            FacebookPostMedia::create(array_merge(['facebook_post_id' => $post->id, 'media_type' => 'image', 'sort_order' => $i], $m));
        }

        return $post;
    }

    /** Old-style absolute Windows path, as saved by uploads on another machine. */
    private function legacyPath(string $relative): string
    {
        return 'C:\\Users\\Old\\Marketing_command\\backend\\storage\\app/public\\' . $relative;
    }

    private function cdnUrl(string $name, int $expiresAt): string
    {
        return "https://scontent.fxyz1-1.fna.fbcdn.net/v/t39.30808-6/{$name}.jpg?_nc_cat=1&oh=00_A&oe=" . strtoupper(dechex($expiresAt));
    }

    private function history(int $page = 1, int $perPage = 5)
    {
        return $this->actingAs($this->user)
            ->getJson("/api/v1/posts/history?workspace_id={$this->ws->id}&page={$page}&per_page={$perPage}")
            ->assertOk();
    }

    private function row($response, int $postId): array
    {
        return collect($response->json('data'))->firstWhere('id', $postId);
    }

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    // ── history API ────────────────────────────────────────────────────────

    public function test_single_image_upload_uses_media_route_on_app_url_in_production(): void
    {
        $this->asProduction();
        $rel = "posts/{$this->ws->id}/a1.jpeg";
        $this->storeFile($rel);
        $post = $this->makePost(['fb_post_id' => '1_2', 'platforms' => ['Facebook']], [
            ['file_path' => $this->legacyPath($rel), 'file_url' => "http://localhost:8000/storage/{$rel}"],
        ]);

        $row = $this->row($this->history(), $post->id);

        $this->assertSame("https://api.example.com/api/v1/media/{$rel}", $row['preview']['url']);
        $this->assertSame('image', $row['preview']['kind']);
        $this->assertSame('ok', $row['preview']['status']);
        $this->assertSame("https://api.example.com/api/v1/media/{$rel}", $row['media'][0]['display_url']);
        // Stored values and post fields are returned unchanged.
        $this->assertSame("http://localhost:8000/storage/{$rel}", $row['media'][0]['file_url']);
        $this->assertSame('published', $row['status']);
        $this->assertSame('1_2', $row['fb_post_id']);
    }

    public function test_production_never_returns_localhost_even_when_app_url_is_local(): void
    {
        $this->asProduction();
        config(['app.url' => 'http://localhost:8000']);
        $rel = "posts/{$this->ws->id}/b.jpg";
        $this->storeFile($rel);
        $post = $this->makePost([], [['file_path' => $rel, 'file_url' => "http://localhost:8000/storage/{$rel}"]]);

        $res = $this->actingAs($this->user)
            ->getJson("https://mc-api.example.org/api/v1/posts/history?workspace_id={$this->ws->id}")
            ->assertOk();
        $row = $this->row($res, $post->id);

        $this->assertSame("https://mc-api.example.org/api/v1/media/{$rel}", $row['preview']['url']);
        $this->assertStringNotContainsString('localhost', json_encode($row['preview']));
    }

    public function test_carousel_uses_first_image_by_sort_order_and_keeps_count(): void
    {
        $this->asProduction();
        foreach (['c0', 'c1', 'c2'] as $n) {
            $this->storeFile("posts/{$this->ws->id}/{$n}.jpg");
        }
        $post = $this->makePost(['post_type' => 'multi_image'], [
            ['file_path' => "posts/{$this->ws->id}/c1.jpg", 'sort_order' => 1],
            ['file_path' => "posts/{$this->ws->id}/c2.jpg", 'sort_order' => 2],
            ['file_path' => "posts/{$this->ws->id}/c0.jpg", 'sort_order' => 0],
        ]);

        $row = $this->row($this->history(), $post->id);

        $this->assertSame("https://api.example.com/api/v1/media/posts/{$this->ws->id}/c0.jpg", $row['preview']['url']);
        $this->assertSame(3, $row['preview']['media_count']);
        $this->assertCount(3, array_filter(array_column($row['media'], 'display_url')));
    }

    public function test_video_prefers_custom_thumbnail_then_video_frame_and_imported_poster(): void
    {
        $this->asProduction();
        $ws = $this->ws->id;
        $this->storeFile("posts/{$ws}/v1.mp4", "\x00\x00\x00\x18ftypmp42");
        $this->storeFile("posts/{$ws}/thumbnails/t1.jpg");

        $withThumb = $this->makePost(['post_type' => 'video', 'thumbnail_path' => "posts/{$ws}/thumbnails/t1.jpg", 'thumbnail_url' => "http://localhost:8000/storage/posts/{$ws}/thumbnails/t1.jpg"], [
            ['media_type' => 'video', 'file_path' => $this->legacyPath("posts/{$ws}/v1.mp4"), 'file_url' => 'x'],
        ]);
        $noThumb = $this->makePost(['post_type' => 'video'], [
            ['media_type' => 'video', 'file_path' => "posts/{$ws}/v1.mp4", 'file_url' => 'x'],
        ]);
        // Imported video/reel: media_type video, but the stored file is Meta's poster JPG.
        $importedReel = $this->makePost(['post_type' => 'video', 'fb_post_id' => '9_9'], [
            ['media_type' => 'video', 'file_url' => $this->cdnUrl('poster', time() + 86400)],
        ]);

        $res = $this->history();
        $a = $this->row($res, $withThumb->id)['preview'];
        $b = $this->row($res, $noThumb->id)['preview'];
        $c = $this->row($res, $importedReel->id)['preview'];

        $this->assertSame(['https://api.example.com/api/v1/media/posts/' . $ws . '/thumbnails/t1.jpg', 'image', true], [$a['url'], $a['kind'], $a['is_video']]);
        $this->assertSame(['https://api.example.com/api/v1/media/posts/' . $ws . '/v1.mp4', 'video', true], [$b['url'], $b['kind'], $b['is_video']]);
        $this->assertSame('image', $c['kind']);
        $this->assertSame('remote', $c['status']);
        $this->assertTrue($c['is_video']);
    }

    public function test_missing_files_expired_links_and_unreachable_urls_become_placeholders(): void
    {
        $missing = $this->makePost([], [['file_path' => $this->legacyPath("posts/{$this->ws->id}/gone.jpg"), 'file_url' => 'https://api.example.com/storage/posts/1/gone.jpg']]);
        $expired = $this->makePost(['fb_post_id' => '1_5'], [['file_url' => $this->cdnUrl('old', time() - 3600)]]);
        $local   = $this->makePost([], [['file_url' => 'http://localhost:8000/somewhere/else.jpg']]);
        $none    = $this->makePost(['post_type' => 'single_image'], []);

        $res = $this->history(1, 10);

        $this->assertSame([null, 'missing'], [$this->row($res, $missing->id)['preview']['url'], $this->row($res, $missing->id)['preview']['status']]);
        $this->assertSame('missing', $this->row($res, $missing->id)['media'][0]['media_status']);
        $this->assertSame([null, 'expired'], [$this->row($res, $expired->id)['preview']['url'], $this->row($res, $expired->id)['preview']['status']]);
        $this->assertSame([null, 'missing'], [$this->row($res, $local->id)['preview']['url'], $this->row($res, $local->id)['preview']['status']]);
        $this->assertSame([null, 'none'], [$this->row($res, $none->id)['preview']['url'], $this->row($res, $none->id)['preview']['status']]);
    }

    public function test_every_page_carries_previews(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->storeFile("posts/{$this->ws->id}/p{$i}.jpg");
            $this->makePost(['content' => "Post {$i}"], [['file_path' => "posts/{$this->ws->id}/p{$i}.jpg"]]);
        }

        foreach ([1, 2] as $page) {
            $rows = $this->history($page)->json('data');
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertSame('ok', $row['preview']['status']);
                $this->assertStringContainsString('/api/v1/media/posts/', $row['preview']['url']);
            }
        }
    }

    // ── public media route ─────────────────────────────────────────────────

    public function test_media_route_serves_files_publicly_with_content_type(): void
    {
        $ws = $this->ws->id;
        $this->storeFile("posts/{$ws}/img.jpeg");
        $this->storeFile("posts/{$ws}/clip.mp4", "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 64));

        $img = $this->get("/api/v1/media/posts/{$ws}/img.jpeg"); // no auth
        $img->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('public', $img->headers->get('Cache-Control'));

        $this->get("/api/v1/media/posts/{$ws}/clip.mp4")->assertOk()->assertHeader('Content-Type', 'video/mp4');
    }

    public function test_media_route_rejects_missing_files_traversal_and_other_folders(): void
    {
        Storage::disk('public')->put('secret.txt', 'nope');
        Storage::disk('public')->put('other/a.jpg', $this->jpegBytes());

        $this->get("/api/v1/media/posts/{$this->ws->id}/nothing.jpg")->assertNotFound();
        foreach (['/api/v1/media/posts/../secret.txt', '/api/v1/media/posts/1/..%2F..%2Fsecret.txt'] as $traversal) {
            $res = $this->get($traversal);
            $this->assertFalse($res->isSuccessful(), $traversal);
            $this->assertStringNotContainsString('nope', (string) $res->getContent());
        }
        // Outside posts/: the media route does not match (the CORS OPTIONS catch-all answers 405).
        $this->assertFalse($this->get('/api/v1/media/other/a.jpg')->isSuccessful());
        Storage::disk('public')->put("posts/{$this->ws->id}/notes.txt", 'x');
        $this->get("/api/v1/media/posts/{$this->ws->id}/notes.txt")->assertNotFound();
    }

    // ── Facebook sync guard ────────────────────────────────────────────────

    private function page(): FacebookPage
    {
        return FacebookPage::create(['workspace_id' => $this->ws->id, 'page_id' => '555', 'page_name' => 'Page', 'page_access_token' => 'EAA_page']);
    }

    public function test_sync_never_replaces_uploaded_media_with_meta_cdn_link(): void
    {
        $page = $this->page();
        $rel = "posts/{$this->ws->id}/orig.jpg";
        $this->storeFile($rel);
        $post = $this->makePost(['fb_post_id' => '555_1', 'facebook_page_id' => $page->id], [
            ['file_path' => $this->legacyPath($rel), 'file_url' => "https://api.example.com/storage/{$rel}"],
        ]);
        Http::fake();

        app(FacebookGraphService::class)->persistFacebookPost($page, '555_1', ['status' => 'published'], $this->cdnUrl('meta', time() + 86400));

        $media = $post->media()->get();
        $this->assertCount(1, $media);
        $this->assertSame("https://api.example.com/storage/{$rel}", $media[0]->file_url);
        $this->assertSame($this->legacyPath($rel), $media[0]->file_path);
        Http::assertNothingSent();
    }

    public function test_sync_keeps_a_local_copy_of_imported_images(): void
    {
        $page = $this->page();
        $url = $this->cdnUrl('imported', time() + 86400);
        Http::fake(['scontent.fxyz1-1.fna.fbcdn.net/*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])]);

        $post = app(FacebookGraphService::class)->persistFacebookPost($page, '555_2', [
            'facebook_page_id' => $page->id, 'status' => 'published', 'post_type' => 'single_image', 'content' => 'Imported',
        ], $url);

        $media = $post->media()->first();
        $this->assertSame($url, $media->file_url); // Meta's link kept for other consumers
        $this->assertSame("posts/{$this->ws->id}/synced/555_2.jpg", $media->file_path);
        Storage::disk('public')->assertExists($media->file_path);

        // The copy outlives Meta's link.
        $this->travel(10)->days();
        $row = $this->row($this->history(), $post->id);
        $this->assertSame('ok', $row['preview']['status']);
        $this->assertStringEndsWith("/api/v1/media/posts/{$this->ws->id}/synced/555_2.jpg", $row['preview']['url']);
    }

    // ── repair command ─────────────────────────────────────────────────────

    public function test_repair_dry_run_writes_nothing(): void
    {
        $post = $this->makePost(['fb_post_id' => '555_3'], [['file_url' => $this->cdnUrl('old', time() - 60)]]);
        $before = $post->media()->first()->toArray();

        $this->artisan('posts:repair-media', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $post->media()->first()->toArray());
        Http::assertNothingSent();
    }

    public function test_repair_restores_expired_imported_images_without_touching_posts(): void
    {
        $page = $this->page();
        $scheduledAt = now()->subDays(3)->startOfMinute();
        $expired = $this->makePost([
            'fb_post_id' => '555_4', 'facebook_page_id' => $page->id, 'post_type' => 'video',
            'content' => 'Keep me', 'scheduled_at' => $scheduledAt, 'platform_statuses' => ['facebook' => ['status' => 'published', 'id' => '555_4']],
        ], [['media_type' => 'video', 'file_url' => $this->cdnUrl('expired', time() - 3600)]]);
        $uploadGone = $this->makePost(['fb_post_id' => '555_5'], [['file_path' => $this->legacyPath("posts/{$this->ws->id}/lost.jpg"), 'file_url' => 'https://api.example.com/storage/x.jpg']]);
        $postBefore = FacebookPost::find($expired->id)->getAttributes();
        $goneBefore = $uploadGone->media()->first()->getAttributes();

        $fresh = $this->cdnUrl('fresh', time() + 86400);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['full_picture' => $fresh, 'id' => '555_4'], 200),
            'scontent.fxyz1-1.fna.fbcdn.net/*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('posts:repair-media')->assertSuccessful();

        $media = $expired->media()->first();
        $this->assertSame("posts/{$this->ws->id}/synced/555_4.jpg", $media->file_path);
        $this->assertSame($fresh, $media->file_url);
        Storage::disk('public')->assertExists($media->file_path);
        $this->assertSame($postBefore, FacebookPost::find($expired->id)->getAttributes(), 'post row must not change');
        $this->assertSame($goneBefore, $uploadGone->media()->first()->getAttributes(), 'missing uploads are only reported');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com') && $r->method() === 'GET' && str_contains($r->url(), 'fields=full_picture'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');

        $row = $this->row($this->history(), $expired->id);
        $this->assertSame(['image', 'ok', true], [$row['preview']['kind'], $row['preview']['status'], $row['preview']['is_video']]);
    }
}
